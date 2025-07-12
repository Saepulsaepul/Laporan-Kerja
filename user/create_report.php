<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

date_default_timezone_set('Asia/Jakarta');

checkLogin('user');

// Ambil error dan data form lama dari session jika ada (setelah redirect dari error)
$error = $_SESSION['form_error'] ?? '';
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_error'], $_SESSION['form_data']); // Hapus setelah diambil

// Logika untuk mengambil kategori dari database tetap di sini
// karena data ini dibutuhkan untuk membangun tampilan form.
$pdo = getConnection();
$stmt = $pdo->query("
    SELECT c.*, s.section_name 
    FROM categories c 
    LEFT JOIN sections s ON c.section_id = s.id 
    ORDER BY s.id ASC, c.category_name ASC
");
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesBySection = [];
foreach ($allCategories as $category) {
    $sectionId = $category['section_id'] ?? 'no_section';
    $sectionName = $category['section_name'] ?? 'Tanpa Seksi';
    if (!isset($categoriesBySection[$sectionId])) {
        $categoriesBySection[$sectionId] = [
            'section_name' => $sectionName,
            'categories' => []
        ];
    }
    $categoriesBySection[$sectionId]['categories'][] = $category;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Laporan Baru - Sistem Laporan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/create_report.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand fs-4" href="dashboard.php">
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
            <div class="col-lg-9">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h2 mb-0">Formulir Laporan Baru</h1>
                    <a class="btn btn-outline-secondary" href="dashboard.php">
                        <i class="fas fa-arrow-left me-2"></i>Kembali
                    </a>
                </div>

                <div class="card main-card">
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                                <div><?php echo $error; ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="wizard-steps mb-4">Langkah 1 dari 3: Isi Detail Laporan</div>

                        <form method="POST" action="process_create_report.php" enctype="multipart/form-data" novalidate>
                            
                            <fieldset class="mb-4 p-3 bg-light rounded">
                                <legend class="h6 text-muted mb-3">Informasi Pelapor (Otomatis)</legend>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small">Nama</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['nama_lengkap']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Tanggal</label>
                                        <input type="text" class="form-control" value="<?php echo formatTanggalIndonesia(date('Y-m-d')); ?>" readonly>
                                    </div>
                                </div>
                            </fieldset>

                            <hr class="my-4">

                            <div class="mb-4">
                                <label for="category_id" class="form-label form-step">1. Pilih Kategori Laporan <span class="text-danger small">(Wajib)</span></label>
                                <select class="form-select form-select-lg" id="category_id" name="category_id" required>
                                    <option value="" disabled selected>-- Tekan untuk memilih kategori --</option>
                                    <?php foreach ($categoriesBySection as $sectionId => $sectionData): ?>
                                        <optgroup label="<?php echo htmlspecialchars($sectionData['section_name']); ?>">
                                            <?php foreach ($sectionData['categories'] as $category): ?>
                                                <option value="<?php echo $category['id']; ?>" <?php echo ($form_data['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-5">
                                <label for="keterangan" class="form-label form-step">2. Tulis Keterangan <span class="text-danger small">(Wajib)</span></label>
                                <textarea class="form-control" id="keterangan" name="keterangan" rows="6" 
                                          placeholder="Jelaskan detail laporan Anda di sini..." required><?php echo htmlspecialchars($form_data['keterangan'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label form-step">3. Lampirkan Foto <span class="text-muted small">(Opsional)</span></label>
                                <div class="upload-area">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <p class="lead">Seret file ke sini, atau pilih dari perangkat Anda.</p>
                                    
                                    <input type="file" id="foto_bukti" name="foto_bukti" accept="image/*" class="d-none">
                                    
                                    <div class="row g-2 justify-content-center">
                                        <div class="col-md-5">
                                            <button type="button" class="btn btn-outline-success w-100 p-2" onclick="document.getElementById('foto_bukti').click();">
                                                <i class="fas fa-images me-2"></i> Pilih dari Galeri
                                            </button>
                                        </div>
                                        <div class="col-md-5">
                                            <button type="button" id="btnAmbilFoto" class="btn btn-outline-primary w-100 p-2">
                                                <i class="fas fa-camera me-2"></i> Ambil dengan Kamera
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="imagePreview" class="mb-4 text-center" style="display: none;">
                                <label class="form-label fw-bold">Preview Foto:</label>
                                <div><img id="preview" src="#" alt="Preview Foto"></div>
                            </div>
                            
                            <hr class="my-4">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold"><i class="fas fa-paper-plane me-2"></i> KIRIM LAPORAN</button>
                                <a href="dashboard.php" class="btn btn-light text-muted">Batal</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="cameraModal" tabindex="-1">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ambil Foto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 d-flex justify-content-center align-items-center bg-dark">
                    <video id="video" playsinline autoplay muted style="width: 100%; height: 100%; object-fit: cover;"></video>
                    <canvas id="canvas" class="d-none"></canvas>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" id="btnJepret" class="btn btn-danger btn-lg rounded-circle" style="width: 70px; height: 70px;"><i class="fas fa-camera fa-2x"></i></button>
                    <button type="button" id="btnGunakanFoto" class="btn btn-success btn-lg" style="display:none;"><i class="fas fa-check me-2"></i> Gunakan Foto</button>
                    <button type="button" id="btnAmbilUlang" class="btn btn-secondary btn-lg" style="display:none;"><i class="fas fa-sync-alt me-2"></i> Ambil Ulang</button>
                    <button type="button" id="btnSwitchCamera" class="btn btn-info btn-lg position-absolute end-0 me-3"><i class="fas fa-camera-rotate"></i></button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="assets/js/create_report.js"></script>
</body>
</html>