<?php
// --- FUNGSI PHP TIDAK DIUBAH ---
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Ambil parameter pencarian
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $nama_service = sanitizeInput($_POST['nama_service']);
    $deskripsi = sanitizeInput($_POST['deskripsi']);
    $durasi_menit = (int)$_POST['durasi_menit'];
    $harga = filter_input(INPUT_POST, 'harga', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $kategori = isset($_POST['kategori']) ? sanitizeInput($_POST['kategori']) : 'Residential';

    // Validasi
    if (empty($nama_service) || empty($deskripsi) || $durasi_menit <= 0 || $harga <= 0) {
        $_SESSION['error'] = 'Semua data harus diisi dengan benar! Durasi dan harga harus lebih dari 0.';
    } else {
        try {
            if ($action === 'create') {
                // Generate kode service otomatis
                $stmt = $pdo->query("SELECT COUNT(*) + 1 as next_id FROM services");
                $next_id = $stmt->fetchColumn();
                $kode_service = 'SRV-' . str_pad($next_id, 3, '0', STR_PAD_LEFT);
                
                $stmt = $pdo->prepare("INSERT INTO services (kode_service, nama_service, deskripsi, durasi_menit, harga, kategori) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$kode_service, $nama_service, $deskripsi, $durasi_menit, $harga, $kategori]);
                $_SESSION['success'] = 'Layanan baru berhasil ditambahkan!';
            } elseif ($action === 'update' && $id) {
                $stmt = $pdo->prepare("UPDATE services SET nama_service = ?, deskripsi = ?, durasi_menit = ?, harga = ?, kategori = ? WHERE id = ?");
                $stmt->execute([$nama_service, $deskripsi, $durasi_menit, $harga, $kategori, $id]);
                $_SESSION['success'] = 'Data layanan berhasil diperbarui!';
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { 
                $_SESSION['error'] = 'Nama layanan sudah ada. Gunakan nama yang berbeda.'; 
            } else { 
                $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage(); 
            }
        }
    }
    header("Location: services.php" . (!empty($search) ? "?search=" . urlencode($search) : ""));
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    // Cek apakah layanan digunakan di jadwal
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal WHERE service_id = ?");
        $stmt->execute([$id]);
        $hasSchedules = $stmt->fetchColumn();
        
        if ($hasSchedules > 0) {
            $_SESSION['error'] = 'Tidak dapat menghapus! Layanan ini masih digunakan dalam jadwal.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = 'Layanan berhasil dihapus!';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal menghapus layanan: ' . $e->getMessage();
    }
    header("Location: services.php" . (!empty($search) ? "?search=" . urlencode($search) : ""));
    exit();
}

// Filter kategori
$kategori_filter = isset($_GET['kategori']) ? sanitizeInput($_GET['kategori']) : 'all';

try {
    // Query dengan pencarian dan filter
    $sql = "SELECT id, kode_service, nama_service, deskripsi, durasi_menit, harga, kategori, created_at FROM services WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (nama_service LIKE ? OR deskripsi LIKE ? OR kode_service LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if ($kategori_filter !== 'all') {
        $sql .= " AND kategori = ?";
        $params[] = $kategori_filter;
    }
    
    $sql .= " ORDER BY nama_service ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Hitung statistik kategori
    $kategori_counts = $pdo->query("SELECT kategori, COUNT(*) as count FROM services GROUP BY kategori")->fetchAll(PDO::FETCH_ASSOC);
    
    // Hitung total pendapatan potensial
    $total_pendapatan = $pdo->query("SELECT SUM(harga) as total FROM services")->fetchColumn();
    $total_layanan = count($services);
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data layanan: " . $e->getMessage();
    $services = [];
    $kategori_counts = [];
    $total_pendapatan = 0;
    $total_layanan = 0;
}

$pageTitle = 'Kelola Layanan';

require_once 'includes/header.php';
?>

<style>
    .service-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        height: 100%;
        overflow: hidden;
    }
    .service-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .service-card-header {
        padding: 1.5rem;
        text-align: center;
        border-top-left-radius: 16px;
        border-top-right-radius: 16px;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-bottom: 1px solid #e9ecef;
        position: relative;
    }
    .service-icon {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        color: white;
        font-weight: 600;
        font-size: 1.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        border: 4px solid white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }
    .service-code {
        position: absolute;
        top: 10px;
        right: 10px;
        background-color: #6c757d;
        color: white;
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-weight: 600;
    }
    .service-card-body {
        padding: 1.5rem;
        flex-grow: 1;
    }
    .service-name {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.75rem;
        color: #2c3e50;
    }
    .service-description {
        color: #6c757d;
        margin-bottom: 1.5rem;
        line-height: 1.6;
        min-height: 80px;
    }
    .service-details {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .service-details li {
        display: flex;
        align-items: center;
        margin-bottom: 0.75rem;
        color: #495057;
    }
    .service-details i {
        color: #6c757d;
        width: 24px;
        text-align: center;
        margin-right: 0.75rem;
    }
    .service-price {
        font-size: 1.5rem;
        font-weight: 700;
        color: #198754;
        text-align: center;
        margin: 1rem 0;
    }
    .service-card-footer {
        padding: 1rem;
        background-color: #f8f9fa;
        border-bottom-left-radius: 16px;
        border-bottom-right-radius: 16px;
        border-top: 1px solid #e9ecef;
    }
    .empty-state-container {
        background-color: #f8f9fa;
        padding: 4rem;
        border-radius: 12px;
        border: 1px dashed #dee2e6;
    }
    .search-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .search-results-info {
        background-color: #e7f5ff;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
    }
    .stats-card {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        margin-bottom: 1.5rem;
    }
    .stats-card .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
    }
    .stats-card .stat-label {
        color: #6c757d;
        font-size: 0.9rem;
    }
    .stats-card.service-stats {
        border-left: 5px solid #0d6efd;
    }
    .kategori-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-weight: 600;
        display: inline-block;
        margin-top: 0.5rem;
    }
    .kategori-residential {
        background-color: #e7f5ff;
        color: #0c63e4;
    }
    .kategori-commercial {
        background-color: #e7fff3;
        color: #198754;
    }
    .kategori-industrial {
        background-color: #fff3e7;
        color: #fd7e14;
    }
    .filter-badge {
        font-size: 0.8rem;
        padding: 0.3rem 0.7rem;
        border-radius: 50px;
        margin-right: 0.5rem;
        margin-bottom: 0.5rem;
        display: inline-block;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
    }
    .filter-active {
        background-color: #0d6efd;
        color: white;
    }
    .filter-inactive {
        background-color: #f8f9fa;
        color: #6c757d;
        border: 1px solid #dee2e6;
    }
    .filter-inactive:hover {
        background-color: #e9ecef;
    }
    .search-highlight {
        background-color: #fff3cd;
        padding: 0 0.2rem;
        border-radius: 3px;
        font-weight: 600;
    }
    .kategori-filter-group {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .kategori-filter-group .btn {
        font-size: 0.85rem;
        padding: 0.4rem 0.8rem;
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
                <h1 class="h2"><i class="fas fa-concierge-bell me-2"></i><?php echo $pageTitle; ?></h1>
                <button type="button" id="btnTambahLayanan" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i>Tambah Layanan
                </button>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Stats Card -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="stats-card service-stats">
                        <div class="stat-number text-primary"><?php echo $total_layanan; ?></div>
                        <div class="stat-label">Total Layanan Tersedia</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card service-stats">
                        <div class="stat-number text-success">Rp <?php echo number_format($total_pendapatan, 0, ',', '.'); ?></div>
                        <div class="stat-label">Total Nilai Layanan</div>
                    </div>
                </div>
            </div>

            <!-- Search Card -->
            <div class="search-card">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <form method="GET" action="services.php" class="row g-2">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Cari nama layanan, deskripsi, atau kode..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                    <button class="btn btn-primary" type="submit">Cari</button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kategori-filter-group">
                                    <a href="services.php<?php echo !empty($search) ? '?search=' . urlencode($search) : ''; ?>" 
                                       class="btn btn-outline-secondary <?php echo $kategori_filter === 'all' ? 'active' : ''; ?>">
                                        Semua
                                    </a>
                                    <a href="?kategori=Residential<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                                       class="btn btn-outline-primary <?php echo $kategori_filter === 'Residential' ? 'active' : ''; ?>">
                                        Residential
                                    </a>
                                    <a href="?kategori=Commercial<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                                       class="btn btn-outline-success <?php echo $kategori_filter === 'Commercial' ? 'active' : ''; ?>">
                                        Commercial
                                    </a>
                                    <a href="?kategori=Industrial<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                                       class="btn btn-outline-warning <?php echo $kategori_filter === 'Industrial' ? 'active' : ''; ?>">
                                        Industrial
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="services.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-1"></i>Reset Filter
                        </a>
                    </div>
                </div>
                
                <!-- Search Results Info -->
                <?php if (!empty($search) || $kategori_filter !== 'all'): ?>
                <div class="search-results-info mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-info-circle me-2"></i>
                            Menampilkan <strong><?php echo $total_layanan; ?></strong> hasil 
                            <?php if (!empty($search)): ?>
                                untuk pencarian "<strong><?php echo htmlspecialchars($search); ?></strong>"
                            <?php endif; ?>
                            <?php if ($kategori_filter !== 'all'): ?>
                                dengan kategori <strong><?php echo $kategori_filter; ?></strong>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($search)): ?>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSearch()">
                                <i class="fas fa-times me-1"></i>Hapus Pencarian
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Kategori Counts -->
                <?php if (!empty($kategori_counts) && empty($search)): ?>
                <div class="mt-3">
                    <div class="d-flex flex-wrap">
                        <?php 
                        $total_all = 0;
                        foreach ($kategori_counts as $kategori_count): 
                            $total_all += $kategori_count['count'];
                            
                            // Tentukan warna badge berdasarkan kategori
                            $badge_class = '';
                            switch($kategori_count['kategori']) {
                                case 'Residential':
                                    $badge_class = 'btn-outline-primary';
                                    break;
                                case 'Commercial':
                                    $badge_class = 'btn-outline-success';
                                    break;
                                case 'Industrial':
                                    $badge_class = 'btn-outline-warning';
                                    break;
                                default:
                                    $badge_class = 'btn-outline-secondary';
                            }
                        ?>
                        <a href="?kategori=<?php echo $kategori_count['kategori']; ?>" 
                           class="filter-badge <?php echo $kategori_filter === $kategori_count['kategori'] ? 'filter-active' : 'filter-inactive'; ?>">
                            <?php echo $kategori_count['kategori']; ?>: <strong><?php echo $kategori_count['count']; ?></strong>
                        </a>
                        <?php endforeach; ?>
                        <a href="services.php" class="filter-badge <?php echo $kategori_filter === 'all' ? 'filter-active' : 'filter-inactive'; ?>">
                            Semua: <strong><?php echo $total_all; ?></strong>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="row">
                <?php if (empty($services)): ?>
                    <div class="col">
                        <div class="text-center empty-state-container">
                            <i class="fas fa-box-open fa-4x text-muted mb-4"></i>
                            <h4 class="text-dark fw-bold">
                                <?php echo !empty($search) || $kategori_filter !== 'all' ? 'Layanan Tidak Ditemukan' : 'Belum Ada Layanan'; ?>
                            </h4>
                            <p class="text-muted">
                                <?php if (!empty($search)): ?>
                                    Tidak ditemukan layanan dengan kata kunci "<?php echo htmlspecialchars($search); ?>"
                                    <?php if ($kategori_filter !== 'all'): ?>
                                        dan kategori "<?php echo $kategori_filter; ?>"
                                    <?php endif; ?>
                                <?php elseif ($kategori_filter !== 'all'): ?>
                                    Tidak ada layanan dengan kategori "<?php echo $kategori_filter; ?>"
                                <?php else: ?>
                                    Tekan tombol "Tambah Layanan" untuk menambahkan layanan pest control.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php else: 
                    $iconColors = ['#0d6efd', '#6f42c1', '#d63384', '#198754', '#fd7e14', '#20c997'];
                    $serviceIcons = ['fas fa-cloud', 'fas fa-spray-can', 'fas fa-home', 'fas fa-warehouse', 'fas fa-seedling', 'fas fa-bug'];
                    $colorIndex = 0;
                    
                    // Fungsi untuk highlight teks pencarian
                    function highlightText($text, $search) {
                        if (empty($search) || empty($text)) {
                            return htmlspecialchars($text);
                        }
                        $pattern = '/' . preg_quote($search, '/') . '/i';
                        return preg_replace($pattern, '<span class="search-highlight">$0</span>', htmlspecialchars($text));
                    }
                    
                    foreach ($services as $service): 
                        $iconColor = $iconColors[$colorIndex % count($iconColors)];
                        $serviceIcon = $serviceIcons[$colorIndex % count($serviceIcons)];
                        $colorIndex++;
                        
                        // Format durasi
                        $jam = floor($service['durasi_menit'] / 60);
                        $menit = $service['durasi_menit'] % 60;
                        $durasi = '';
                        if ($jam > 0) $durasi .= $jam . ' jam ';
                        if ($menit > 0) $durasi .= $menit . ' menit';
                        
                        // Format harga
                        $hargaFormatted = 'Rp ' . number_format($service['harga'], 0, ',', '.');
                        
                        // Tentukan kelas kategori
                        $kategori_class = '';
                        switch($service['kategori']) {
                            case 'Residential':
                                $kategori_class = 'kategori-residential';
                                break;
                            case 'Commercial':
                                $kategori_class = 'kategori-commercial';
                                break;
                            case 'Industrial':
                                $kategori_class = 'kategori-industrial';
                                break;
                        }
                    ?>
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="service-card">
                                <div class="service-card-header">
                                    <?php if (!empty($service['kode_service'])): ?>
                                    <span class="service-code"><?php echo highlightText($service['kode_service'], $search); ?></span>
                                    <?php endif; ?>
                                    <div class="service-icon" style="background-color: <?php echo $iconColor; ?>;">
                                        <i class="<?php echo $serviceIcon; ?>"></i>
                                    </div>
                                    <h5 class="service-name"><?php echo highlightText($service['nama_service'], $search); ?></h5>
                                    <?php if (!empty($service['kategori'])): ?>
                                    <span class="kategori-badge <?php echo $kategori_class; ?>">
                                        <?php echo $service['kategori']; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="service-card-body">
                                    <p class="service-description"><?php echo nl2br(highlightText($service['deskripsi'], $search)); ?></p>
                                    
                                    <div class="service-price">
                                        <?php echo $hargaFormatted; ?>
                                    </div>
                                    
                                    <ul class="service-details">
                                        <li>
                                            <i class="fas fa-clock fa-fw"></i>
                                            <span><strong>Durasi:</strong> <?php echo $durasi; ?></span>
                                        </li>
                                        <li>
                                            <i class="fas fa-calendar-alt fa-fw"></i>
                                            <span><strong>Dibuat:</strong> <?php echo formatTanggalIndonesia(date('Y-m-d', strtotime($service['created_at']))); ?></span>
                                        </li>
                                    </ul>
                                </div>
                                <div class="service-card-footer">
                                    <div class="btn-group w-100">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit" 
                                                data-id="<?php echo $service['id']; ?>" 
                                                data-nama_service="<?php echo htmlspecialchars($service['nama_service']); ?>" 
                                                data-deskripsi="<?php echo htmlspecialchars($service['deskripsi']); ?>" 
                                                data-durasi_menit="<?php echo $service['durasi_menit']; ?>" 
                                                data-harga="<?php echo $service['harga']; ?>"
                                                data-kategori="<?php echo $service['kategori']; ?>">
                                            <i class="fas fa-edit me-2"></i>Edit
                                        </button>
                                        <a href="?delete=<?php echo $service['id']; echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Hapus layanan ini? Tindakan ini tidak dapat dibatalkan.')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit Layanan -->
<div class="modal fade" id="serviceModal" tabindex="-1" aria-labelledby="serviceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="serviceForm" method="POST" action="services.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="serviceModalLabel">Tambah Layanan Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="formServiceId">
                    
                    <!-- Juga simpan parameter pencarian untuk redirect -->
                    <input type="hidden" name="search_redirect" value="<?php echo htmlspecialchars($search); ?>">

                    <div class="mb-3">
                        <label for="nama_service" class="form-label">Nama Layanan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nama_service" name="nama_service" required 
                               placeholder="Contoh: Fogging Nyamuk, Fumigasi Gudang">
                    </div>
                    
                    <div class="mb-3">
                        <label for="deskripsi" class="form-label">Deskripsi Layanan <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="deskripsi" name="deskripsi" rows="3" required 
                                  placeholder="Jelaskan detail layanan ini..."></textarea>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="durasi_menit" class="form-label">Durasi (menit) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="durasi_menit" name="durasi_menit" 
                                       min="1" required value="60">
                                <span class="input-group-text">menit</span>
                            </div>
                            <small class="form-text text-muted">Durasi pelaksanaan layanan dalam menit</small>
                        </div>
                        <div class="col-md-6">
                            <label for="harga" class="form-label">Harga <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control" id="harga" name="harga" 
                                       min="0" step="1000" required value="0">
                            </div>
                            <small class="form-text text-muted">Harga layanan dalam Rupiah</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="kategori" class="form-label">Kategori <span class="text-danger">*</span></label>
                        <select class="form-select" id="kategori" name="kategori" required>
                            <option value="Residential">Residential (Rumah/Rumah Tangga)</option>
                            <option value="Commercial">Commercial (Komersial/Perkantoran)</option>
                            <option value="Industrial">Industrial (Industri/Gudang)</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Durasi akan ditampilkan otomatis dalam format jam dan menit.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Layanan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const serviceModalEl = document.getElementById('serviceModal');
    const serviceModal = new bootstrap.Modal(serviceModalEl);
    const form = document.getElementById('serviceForm');
    const modalTitle = document.getElementById('serviceModalLabel');
    const submitButton = form.querySelector('button[type="submit"]');
    const formAction = document.getElementById('formAction');
    const formServiceId = document.getElementById('formServiceId');
    
    // Format harga saat input
    const hargaInput = document.getElementById('harga');
    if (hargaInput) {
        hargaInput.addEventListener('input', function() {
            let value = this.value.replace(/[^\d]/g, '');
            if (value) {
                this.value = parseInt(value);
            }
        });
    }
    
    function setupAddModal() {
        form.reset();
        modalTitle.textContent = 'Tambah Layanan Baru';
        submitButton.textContent = 'Simpan Layanan';
        submitButton.classList.remove('btn-warning');
        submitButton.classList.add('btn-primary');
        formAction.value = 'create';
        formServiceId.value = '';
        
        // Set default values
        document.getElementById('durasi_menit').value = 60;
        document.getElementById('harga').value = 0;
        document.getElementById('kategori').value = 'Residential';
        
        serviceModal.show();
    }

    function setupEditModal(button) {
        form.reset();
        const data = button.dataset;
        modalTitle.textContent = 'Edit Data Layanan';
        submitButton.textContent = 'Update Data';
        submitButton.classList.remove('btn-primary');
        submitButton.classList.add('btn-warning');
        formAction.value = 'update';
        formServiceId.value = data.id;
        
        // Set values from data attributes
        document.getElementById('nama_service').value = data.nama_service;
        document.getElementById('deskripsi').value = data.deskripsi;
        document.getElementById('durasi_menit').value = data.durasi_menit;
        document.getElementById('harga').value = data.harga;
        document.getElementById('kategori').value = data.kategori || 'Residential';
        
        serviceModal.show();
    }

    document.getElementById('btnTambahLayanan').addEventListener('click', setupAddModal);
    document.querySelectorAll('.btn-edit').forEach(button => {
        button.addEventListener('click', function() { setupEditModal(this); });
    });

    // Validasi form sebelum submit
    form.addEventListener('submit', function(event) {
        const durasi = document.getElementById('durasi_menit').value;
        const harga = document.getElementById('harga').value;
        
        if (durasi <= 0) {
            alert('Durasi harus lebih dari 0 menit');
            event.preventDefault();
            return;
        }
        
        if (harga <= 0) {
            alert('Harga harus lebih dari 0');
            event.preventDefault();
            return;
        }
    });
    
    // Fungsi untuk menghapus pencarian
    window.clearSearch = function() {
        window.location.href = 'services.php<?php echo $kategori_filter !== 'all' ? '?kategori=' . $kategori_filter : ''; ?>';
    };
    
    // Fokus ke input search jika ada parameter search
    const searchParam = new URLSearchParams(window.location.search).get('search');
    if (searchParam) {
        document.querySelector('input[name="search"]').focus();
    }
});
</script>

</body>
</html>