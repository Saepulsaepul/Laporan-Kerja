<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$user_type = isset($_GET['type']) ? $_GET['type'] : 'workers'; // 'workers' atau 'admins'

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $username = sanitizeInput($_POST['username']);
    $nama = sanitizeInput($_POST['nama']);
    $telepon = sanitizeInput($_POST['telepon']);
    $password = $_POST['password'];
    $type = $_POST['type']; // 'worker' atau 'admin'
    
    // Untuk pekerja saja, ambil email
    $email = '';
    if ($type === 'worker') {
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    }

    // Validasi berbeda untuk pekerja dan admin
    if (empty($username) || empty($nama)) {
        $_SESSION['error'] = 'Username dan Nama harus diisi!';
    } elseif ($type === 'worker' && !empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Email tidak valid!';
    } elseif ($action === 'create' && empty($password)) {
        $_SESSION['error'] = 'Password wajib diisi untuk pengguna baru!';
    } else {
        try {
            if ($type === 'admin') {
                $table = 'admin_users';
            } else {
                $table = 'users';
            }

            if ($action === 'create') {
                $hashedPassword = hashPassword($password);
                if ($type === 'admin') {
                    // Admin: tanpa email
                    $stmt = $pdo->prepare("INSERT INTO $table (username, password, nama, telepon) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $hashedPassword, $nama, $telepon]);
                } else {
                    // Pekerja: dengan email
                    $stmt = $pdo->prepare("INSERT INTO $table (username, password, nama, email, telepon) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $hashedPassword, $nama, $email, $telepon]);
                }
                $_SESSION['success'] = ($type === 'admin' ? 'Admin' : 'Pekerja') . ' baru berhasil ditambahkan!';
            } elseif ($action === 'update' && $id) {
                if (!empty($password)) {
                    $hashedPassword = hashPassword($password);
                    if ($type === 'admin') {
                        $stmt = $pdo->prepare("UPDATE $table SET username = ?, password = ?, nama = ?, telepon = ? WHERE id = ?");
                        $stmt->execute([$username, $hashedPassword, $nama, $telepon, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE $table SET username = ?, password = ?, nama = ?, email = ?, telepon = ? WHERE id = ?");
                        $stmt->execute([$username, $hashedPassword, $nama, $email, $telepon, $id]);
                    }
                } else {
                    if ($type === 'admin') {
                        $stmt = $pdo->prepare("UPDATE $table SET username = ?, nama = ?, telepon = ? WHERE id = ?");
                        $stmt->execute([$username, $nama, $telepon, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE $table SET username = ?, nama = ?, email = ?, telepon = ? WHERE id = ?");
                        $stmt->execute([$username, $nama, $email, $telepon, $id]);
                    }
                }
                $_SESSION['success'] = 'Data ' . ($type === 'admin' ? 'admin' : 'pekerja') . ' berhasil diperbarui!';
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { 
                $_SESSION['error'] = 'Username sudah digunakan oleh ' . ($type === 'admin' ? 'admin lain.' : 'pekerja lain.'); 
            } else { 
                $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage(); 
            }
        }
    }
    header("Location: users.php?type=" . $type . "s");
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $type = isset($_GET['user_type']) ? $_GET['user_type'] : 'worker';
    
    try {
        if ($type === 'admin') {
            // Cek apakah admin yang login adalah yang akan dihapus
            $current_admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
            if ($id == $current_admin_id) {
                $_SESSION['error'] = 'Tidak dapat menghapus akun admin yang sedang login!';
            } else {
                $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['success'] = 'Admin berhasil dihapus!';
            }
        } else {
            // Cek apakah pekerja memiliki jadwal aktif
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal WHERE pekerja_id = ? AND status IN ('Menunggu', 'Berjalan')");
            $stmt->execute([$id]);
            $hasActiveSchedules = $stmt->fetchColumn();
            
            if ($hasActiveSchedules > 0) {
                $_SESSION['error'] = 'Tidak dapat menghapus! Pekerja ini masih memiliki jadwal aktif.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['success'] = 'Pekerja berhasil dihapus!';
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal menghapus ' . ($type === 'admin' ? 'admin' : 'pekerja') . ': ' . $e->getMessage();
    }
    header("Location: users.php?type=" . ($type === 'admin' ? 'admins' : 'workers'));
    exit();
}

try {
    // Ambil data berdasarkan tipe
    if ($user_type === 'admins') {
        // Admin: tidak ada kolom email
        $stmt = $pdo->query("SELECT id, username, nama, telepon, created_at FROM admin_users ORDER BY nama ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $user_role = 'Admin';
    } else {
        // Pekerja: ada kolom email
        $stmt = $pdo->query("SELECT id, username, nama, email, telepon, jabatan, status, created_at FROM users ORDER BY nama ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $user_role = 'Pekerja';
    }
    
    // Statistik
    $total_workers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Aktif'")->fetchColumn();
    $total_admins = $pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data: " . $e->getMessage();
    $users = [];
    $total_workers = $total_admins = 0;
}

$pageTitle = 'Kelola Pengguna';

require_once 'includes/header.php';
?>

<style>
    .user-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        height: 100%;
    }
    .user-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .user-card-header {
        padding: 1.5rem 1.5rem 3.5rem;
        text-align: center;
        border-top-left-radius: 16px;
        border-top-right-radius: 16px;
        position: relative;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    }
    .user-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        color: white;
        font-weight: 600;
        font-size: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        position: absolute;
        bottom: -40px;
        left: 50%;
        transform: translateX(-50%);
        border: 4px solid white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }
    .user-card-body {
        padding: 3rem 1.5rem 1.5rem;
        text-align: center;
        flex-grow: 1;
    }
    .user-name {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    .user-role {
        color: #6c757d;
        margin-bottom: 1.5rem;
    }
    .user-details {
        list-style: none;
        padding: 0;
        text-align: left;
    }
    .user-details li {
        display: flex;
        align-items: center;
        margin-bottom: 0.75rem;
        color: #495057;
    }
    .user-details i {
        color: #6c757d;
        width: 24px;
        text-align: center;
        margin-right: 0.75rem;
    }
    .user-card-footer {
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
    .modal-header {
        background-color: #f8f9fa;
    }
    .nav-pills .nav-link {
        border-radius: 8px;
        padding: 0.75rem 1.5rem;
        margin: 0 0.25rem;
        font-weight: 500;
    }
    .nav-pills .nav-link.active {
        box-shadow: 0 2px 8px rgba(13, 110, 253, 0.25);
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
    .stats-card.worker-stats {
        border-left: 5px solid #0d6efd;
    }
    .stats-card.admin-stats {
        border-left: 5px solid #198754;
    }
    .role-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
        font-weight: 600;
    }
    .badge-worker {
        background-color: #e7f5ff;
        color: #0c63e4;
        border: 1px solid #b3d7ff;
    }
    .badge-admin {
        background-color: #e7fff3;
        color: #198754;
        border: 1px solid #b3e6cb;
    }
    .status-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
        border-radius: 50px;
        font-weight: 600;
    }
    .status-aktif {
        background-color: #d4edda;
        color: #155724;
    }
    .status-nonaktif {
        background-color: #f8d7da;
        color: #721c24;
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
                <h1 class="h2"><i class="fas fa-user-tie me-2"></i><?php echo $pageTitle; ?></h1>
                <div>
                    <button type="button" id="btnTambahUser" class="btn btn-primary">
                        <i class="fas fa-user-plus me-2"></i>Tambah <?php echo $user_role; ?>
                    </button>
                </div>
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

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="stats-card worker-stats">
                        <div class="stat-number text-primary"><?php echo $total_workers; ?></div>
                        <div class="stat-label">Total Pekerja Aktif</div>
                        <a href="?type=workers" class="btn btn-sm btn-outline-primary mt-2">
                            Lihat Semua <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card admin-stats">
                        <div class="stat-number text-success"><?php echo $total_admins; ?></div>
                        <div class="stat-label">Total Admin</div>
                        <a href="?type=admins" class="btn btn-sm btn-outline-success mt-2">
                            Lihat Semua <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Tabs untuk memilih tipe user -->
            <ul class="nav nav-pills mb-4 justify-content-center">
                <li class="nav-item">
                    <a class="nav-link <?php echo $user_type === 'workers' ? 'active' : ''; ?>" href="?type=workers">
                        <i class="fas fa-user-tie me-2"></i>Pekerja
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $user_type === 'admins' ? 'active' : ''; ?>" href="?type=admins">
                        <i class="fas fa-user-shield me-2"></i>Admin
                    </a>
                </li>
            </ul>

            <div class="row">
                <?php if (empty($users)): ?>
                    <div class="col">
                        <div class="text-center empty-state-container">
                            <?php if ($user_type === 'admins'): ?>
                                <i class="fas fa-user-shield fa-4x text-muted mb-4"></i>
                                <h4 class="text-dark fw-bold">Belum Ada Admin</h4>
                                <p class="text-muted">Tekan tombol "Tambah Admin" untuk menambahkan admin baru.</p>
                            <?php else: ?>
                                <i class="fas fa-user-slash fa-4x text-muted mb-4"></i>
                                <h4 class="text-dark fw-bold">Belum Ada Pekerja</h4>
                                <p class="text-muted">Tekan tombol "Tambah Pekerja" untuk menambahkan pekerja baru.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: 
                    $avatarColors = ['#0d6efd', '#6f42c1', '#d63384', '#198754', '#fd7e14', '#dc3545'];
                    $colorIndex = 0;
                    foreach ($users as $user): 
                        $avatarColor = $avatarColors[$colorIndex % count($avatarColors)];
                        $colorIndex++;
                        $role = $user_type === 'admins' ? 'Admin' : ($user['jabatan'] ?? 'Pekerja');
                    ?>
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="user-card h-100">
                                <div class="user-card-header">
                                    <div class="user-avatar" style="background-color: <?php echo $avatarColor; ?>;">
                                        <span><?php echo strtoupper(substr($user['nama'], 0, 1)); ?></span>
                                    </div>
                                </div>
                                <div class="user-card-body">
                                    <h5 class="user-name"><?php echo htmlspecialchars($user['nama']); ?></h5>
                                    <div class="mb-3">
                                        <span class="role-badge <?php echo $user_type === 'admins' ? 'badge-admin' : 'badge-worker'; ?>">
                                            <i class="fas fa-<?php echo $user_type === 'admins' ? 'shield' : 'user-tie'; ?> me-1"></i>
                                            <?php echo htmlspecialchars($role); ?>
                                        </span>
                                        <?php if ($user_type === 'workers' && isset($user['status'])): ?>
                                        <span class="status-badge ms-2 <?php echo $user['status'] === 'Aktif' ? 'status-aktif' : 'status-nonaktif'; ?>">
                                            <?php echo htmlspecialchars($user['status']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <ul class="user-details">
                                        <li><i class="fas fa-user fa-fw"></i><span><?php echo htmlspecialchars($user['username']); ?></span></li>
                                        <?php if ($user_type === 'workers' && !empty($user['email'])): ?>
                                        <li><i class="fas fa-envelope fa-fw"></i><span><?php echo htmlspecialchars($user['email']); ?></span></li>
                                        <?php endif; ?>
                                        <li><i class="fas fa-phone fa-fw"></i><span><?php echo htmlspecialchars($user['telepon'] ?? '-'); ?></span></li>
                                        <?php if ($user_type === 'workers' && !empty($user['jabatan'])): ?>
                                        <li><i class="fas fa-briefcase fa-fw"></i><span><?php echo htmlspecialchars($user['jabatan']); ?></span></li>
                                        <?php endif; ?>
                                        <li><i class="fas fa-calendar-alt fa-fw"></i><span>Bergabung: <?php echo formatTanggalIndonesia(date('Y-m-d', strtotime($user['created_at']))); ?></span></li>
                                    </ul>
                                </div>
                                <div class="user-card-footer">
                                    <div class="btn-group w-100">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit" 
                                                data-id="<?php echo $user['id']; ?>" 
                                                data-nama="<?php echo htmlspecialchars($user['nama']); ?>" 
                                                <?php if ($user_type === 'workers'): ?>
                                                data-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                                <?php endif; ?>
                                                data-telepon="<?php echo htmlspecialchars($user['telepon'] ?? ''); ?>" 
                                                data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                data-type="<?php echo $user_type === 'admins' ? 'admin' : 'worker'; ?>">
                                            <i class="fas fa-edit me-2"></i>Edit
                                        </button>
                                        <?php 
                                        // Cek ID admin yang sedang login
                                        $current_admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
                                        if (!($user_type === 'admins' && $user['id'] == $current_admin_id)): 
                                        ?>
                                        <a href="?delete=<?php echo $user['id']; ?>&user_type=<?php echo $user_type === 'admins' ? 'admin' : 'worker'; ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Anda yakin ingin menghapus <?php echo $user_type === 'admins' ? 'admin' : 'pekerja'; ?> ini?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
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

<!-- Modal Tambah/Edit User -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="userForm" method="POST" action="users.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">Tambah <?php echo $user_role; ?> Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="formUserId">
                    <input type="hidden" name="type" id="formUserType" value="<?php echo $user_type === 'admins' ? 'admin' : 'worker'; ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="nama" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama" name="nama" required>
                        </div>
                        <div class="col-md-6">
                            <label for="telepon" class="form-label">Telepon</label>
                            <input type="text" class="form-control" id="telepon" name="telepon" placeholder="081234567890">
                        </div>
                    </div>
                    
                    <?php if ($user_type === 'workers'): ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" placeholder="pekerja@example.com">
                    </div>
                    <?php endif; ?>
                    
                    <hr class="my-4">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password">
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small id="passwordHelp" class="form-text text-muted">Wajib diisi untuk pengguna baru.</small>
                        </div>
                    </div>
                    
                    <?php if ($user_type === 'workers'): ?>
                    <div class="mb-3">
                        <label for="jabatan" class="form-label">Jabatan</label>
                        <input type="text" class="form-control" id="jabatan" name="jabatan" placeholder="Teknisi Pest Control">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const userModalEl = document.getElementById('userModal');
    const userModal = new bootstrap.Modal(userModalEl);
    const form = document.getElementById('userForm');
    const modalTitle = document.getElementById('userModalLabel');
    const submitButton = form.querySelector('button[type="submit"]');
    const passwordInput = document.getElementById('password');
    const passwordHelp = document.getElementById('passwordHelp');
    const formAction = document.getElementById('formAction');
    const formUserId = document.getElementById('formUserId');
    const formUserType = document.getElementById('formUserType');
    const emailField = document.getElementById('email');
    const jabatanField = document.getElementById('jabatan');
    
    function setupAddModal() {
        form.reset();
        const isAdmin = '<?php echo $user_type === 'admins' ? 'true' : 'false'; ?>' === 'true';
        modalTitle.textContent = 'Tambah ' + (isAdmin ? 'Admin' : 'Pekerja') + ' Baru';
        submitButton.textContent = 'Simpan';
        submitButton.classList.remove('btn-warning');
        submitButton.classList.add('btn-primary');
        formAction.value = 'create';
        formUserId.value = '';
        formUserType.value = isAdmin ? 'admin' : 'worker';
        passwordInput.setAttribute('required', 'required');
        passwordHelp.textContent = 'Wajib diisi untuk pengguna baru.';
        
        // Sembunyikan/tampilkan field berdasarkan tipe
        if (emailField) emailField.required = !isAdmin;
        userModal.show();
    }

    function setupEditModal(button) {
        form.reset();
        const data = button.dataset;
        const isAdmin = data.type === 'admin';
        
        modalTitle.textContent = 'Edit Data ' + (isAdmin ? 'Admin' : 'Pekerja');
        submitButton.textContent = 'Update Data';
        submitButton.classList.remove('btn-primary');
        submitButton.classList.add('btn-warning');
        formAction.value = 'update';
        formUserId.value = data.id;
        formUserType.value = data.type;
        document.getElementById('nama').value = data.nama;
        document.getElementById('telepon').value = data.telepon || '';
        document.getElementById('username').value = data.username;
        
        if (!isAdmin && emailField) {
            emailField.value = data.email || '';
        }
        
        passwordInput.removeAttribute('required');
        passwordHelp.textContent = 'Kosongkan jika tidak ingin mengubah password.';
        userModal.show();
    }

    document.getElementById('btnTambahUser').addEventListener('click', setupAddModal);
    document.querySelectorAll('.btn-edit').forEach(button => {
        button.addEventListener('click', function() { setupEditModal(this); });
    });

    const togglePassword = document.getElementById("togglePassword");
    if (togglePassword) {
        togglePassword.addEventListener("click", function () {
            const type = passwordInput.getAttribute("type") === "password" ? "text" : "password";
            passwordInput.setAttribute("type", type);
            this.querySelector("i").classList.toggle("fa-eye");
            this.querySelector("i").classList.toggle("fa-eye-slash");
        });
    }
    
    // Update tombol tambah berdasarkan tab aktif
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            const isAdminTab = this.getAttribute('href').includes('type=admins');
            const btnTambah = document.getElementById('btnTambahUser');
            const icon = btnTambah.querySelector('i');
            
            if (isAdminTab) {
                btnTambah.innerHTML = '<i class="fas fa-user-plus me-2"></i>Tambah Admin';
            } else {
                btnTambah.innerHTML = '<i class="fas fa-user-plus me-2"></i>Tambah Pekerja';
            }
        });
    });
});
</script>

</body>
</html>