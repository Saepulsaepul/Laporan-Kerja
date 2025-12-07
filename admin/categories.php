<?php
// --- FUNGSI PHP TIDAK DIUBAH ---
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $category_name = sanitizeInput($_POST['category_name']);
    $description = sanitizeInput($_POST['description']);
    $section_id = isset($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if (empty($category_name) || empty($section_id)) {
        $_SESSION['error'] = 'Nama kategori dan seksi tidak boleh kosong!';
    } else {
        try {
            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO categories (category_name, description, section_id) VALUES (?, ?, ?)");
                $stmt->execute([$category_name, $description, $section_id]);
                $_SESSION['success'] = 'Kategori berhasil ditambahkan!';
            } elseif ($action === 'update' && $id) {
                $stmt = $pdo->prepare("UPDATE categories SET category_name = ?, description = ?, section_id = ? WHERE id = ?");
                $stmt->execute([$category_name, $description, $section_id, $id]);
                $_SESSION['success'] = 'Kategori berhasil diperbarui!';
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { $_SESSION['error'] = 'Nama kategori "' . htmlspecialchars($category_name) . '" sudah ada.'; } 
            else { $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage(); }
        }
    }
    header("Location: categories.php");
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success'] = 'Kategori berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal menghapus! Kategori ini masih digunakan oleh laporan.';
    }
    header("Location: categories.php");
    exit();
}

try {
    $stmt = $pdo->query("SELECT * FROM sections ORDER BY id ASC");
    $sections = $stmt->fetchAll();
    
    $stmt = $pdo->query("
        SELECT c.*, s.section_name, COUNT(r.id) as report_count 
        FROM categories c 
        LEFT JOIN sections s ON c.section_id = s.id
        LEFT JOIN reports r ON c.id = r.category_id 
        GROUP BY c.id 
        ORDER BY s.id ASC, c.category_name ASC
    ");
    $allCategories = $stmt->fetchAll();
    
    $categoriesBySection = [];
    foreach ($allCategories as $category) {
        $sectionId = $category['section_id'] ?? 'no_section';
        $sectionName = $category['section_name'] ?? 'Tanpa Seksi';
        if (!isset($categoriesBySection[$sectionId])) {
            $categoriesBySection[$sectionId] = ['section_name' => $sectionName, 'categories' => []];
        }
        $categoriesBySection[$sectionId]['categories'][] = $category;
    }
    
    $editCategory = null;
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $editCategory = $stmt->fetch();
    }
} catch (PDOException $e) {
    $error = "Gagal mengambil data kategori: " . $e->getMessage();
    $categoriesBySection = [];
    $sections = [];
}

$pageTitle = 'Kelola Jadwal';

require_once 'includes/header.php';
?>

<style>
    .section-header {
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 0.5rem;
    }
    .category-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .category-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .category-card-body {
        padding: 1.5rem;
        flex-grow: 1;
        display: flex;
    }
    .category-icon {
        font-size: 1.5rem;
        color: var(--bs-primary);
        background-color: rgba(13, 110, 253, 0.1);
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-right: 1rem;
    }
    .category-name {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    .category-description {
        color: #6c757d;
        font-size: 0.9rem;
    }
    .category-card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.5rem;
        background-color: #f8f9fa;
        border-top: 1px solid #e9ecef;
        border-bottom-left-radius: 12px;
        border-bottom-right-radius: 12px;
    }
    .empty-state-container {
        background-color: #f8f9fa;
        padding: 4rem;
        border-radius: 12px;
        border: 1px dashed #dee2e6;
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
                <h1 class="h2"><i class="fas fa-tags me-2"></i><?php echo $pageTitle; ?></h1>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                    <i class="fas fa-plus me-2"></i>Tambah Kategori
                </button>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <?php if (empty($categoriesBySection) && !$error): ?>
                <div class="text-center empty-state-container mt-4">
                    <i class="fas fa-tag fa-4x text-muted mb-4"></i>
                    <h4 class="text-dark fw-bold">Belum Ada Kategori</h4>
                    <p class="text-muted">Silakan tambahkan kategori baru menggunakan tombol di atas.</p>
                </div>
            <?php else: ?>
                <?php foreach ($categoriesBySection as $sectionId => $sectionData): ?>
                    <div class="mb-5">
                        <h3 class="section-header mb-3">
                            <?php echo htmlspecialchars($sectionData['section_name']); ?>
                            <span class="badge bg-light text-dark ms-2 border"><?php echo count($sectionData['categories']); ?> Kategori</span>
                        </h3>
                        <div class="row">
                            <?php foreach ($sectionData['categories'] as $category): ?>
                                <div class="col-lg-6 mb-4">
                                    <div class="category-card h-100">
                                        <div class="category-card-body">
                                            <div class="category-icon"><i class="fas fa-folder-open"></i></div>
                                            <div>
                                                <h4 class="category-name"><?php echo htmlspecialchars($category['category_name']); ?></h4>
                                                <p class="category-description mb-0"><?php echo htmlspecialchars($category['description'] ?: 'Tidak ada deskripsi.'); ?></p>
                                            </div>
                                        </div>
                                        <div class="category-card-footer">
                                            <span class="badge bg-secondary fw-normal"><i class="fas fa-file-alt me-1"></i><?php echo $category['report_count']; ?> Laporan</span>
                                            <div class="btn-group">
                                                <a href="?edit=<?php echo $category['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                                <?php if ($category['report_count'] == 0): ?>
                                                    <a href="?delete=<?php echo $category['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Anda yakin ingin menghapus kategori ini?')"><i class="fas fa-trash"></i></a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-danger" disabled data-bs-toggle="tooltip" title="Kategori sedang digunakan oleh laporan."><i class="fas fa-trash"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

<div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="categories.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalLabel"><?php echo $editCategory ? 'Edit Kategori' : 'Tambah Kategori Baru'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="<?php echo $editCategory ? 'update' : 'create'; ?>">
                    <?php if ($editCategory): ?><input type="hidden" name="id" value="<?php echo htmlspecialchars($editCategory['id']); ?>"><?php endif; ?>
                    
                    <div class="mb-3"><label for="section_id" class="form-label">Jenis <span class="text-danger">*</span></label><select class="form-select" id="section_id" name="section_id" required><option value="">Pilih Seksi</option><?php foreach ($sections as $section): ?><option value="<?php echo $section['id']; ?>" <?php echo ($editCategory && $editCategory['section_id'] == $section['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($section['section_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label for="category_name" class="form-label">Nama Perusahaan <span class="text-danger">*</span></label><input type="text" class="form-control" id="category_name" name="category_name" value="<?php echo htmlspecialchars($editCategory['category_name'] ?? ''); ?>" required></div>
                    <div class="mb-3"><label for="description" class="form-label">Deskripsi <span class="text-muted small">(Opsional)</span></label><textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($editCategory['description'] ?? ''); ?></textarea></div>
                </div>
                <div class="modal-footer"><a href="categories.php" class="btn btn-secondary">Batal</a><button type="submit" class="btn btn-primary"><?php echo $editCategory ? '<i class="fas fa-save me-2"></i>Update' : '<i class="fas fa-plus me-2"></i>Simpan'; ?></button></div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
<script>
    // Inisialisasi semua tooltip di halaman
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    <?php if (isset($_GET['edit'])): ?>
    // Tampilkan modal secara otomatis jika dalam mode edit
    document.addEventListener("DOMContentLoaded", function() {
        var categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));
        categoryModal.show();
    });
    <?php endif; ?>
</script>
</body>
</html>