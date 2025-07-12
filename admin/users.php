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
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $username = sanitizeInput($_POST['username']);
    $nama_lengkap = sanitizeInput($_POST['nama_lengkap']);
    $jabatan = sanitizeInput($_POST['jabatan']);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];

    if (empty($username) || empty($nama_lengkap) || empty($jabatan) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Semua data harus diisi dengan benar, termasuk format email yang valid!';
    } elseif ($action === 'create' && empty($password)) {
        $_SESSION['error'] = 'Password wajib diisi untuk pengguna baru!';
    } else {
        try {
            if ($action === 'create') {
                $hashedPassword = hashPassword($password);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, jabatan, email) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashedPassword, $nama_lengkap, $jabatan, $email]);
                $_SESSION['success'] = 'Pengguna baru berhasil ditambahkan!';
            } elseif ($action === 'update' && $id) {
                if (!empty($password)) {
                    $hashedPassword = hashPassword($password);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, nama_lengkap = ?, jabatan = ?, email = ? WHERE id = ?");
                    $stmt->execute([$username, $hashedPassword, $nama_lengkap, $jabatan, $email, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, nama_lengkap = ?, jabatan = ?, email = ? WHERE id = ?");
                    $stmt->execute([$username, $nama_lengkap, $jabatan, $email, $id]);
                }
                $_SESSION['success'] = 'Data pengguna berhasil diperbarui!';
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { $_SESSION['error'] = 'Username atau email sudah digunakan oleh pengguna lain.'; } 
            else { $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage(); }
        }
    }
    header("Location: users.php");
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id === (int)$_SESSION['admin_id']) {
        $_SESSION['error'] = 'Anda tidak dapat menghapus akun Anda sendiri dari sini.';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = 'Pengguna berhasil dihapus!';
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Gagal menghapus! Pengguna ini mungkin masih memiliki laporan terkait.';
        }
    }
    header("Location: users.php");
    exit();
}

try {
    $stmt = $pdo->query("SELECT id, username, nama_lengkap, jabatan, email, created_at FROM users ORDER BY nama_lengkap ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Gagal mengambil data pengguna: " . $e->getMessage();
    $users = [];
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
    }
    .user-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .user-card-header {
        padding: 1.5rem 1.5rem 3.5rem; /* Beri ruang lebih di bawah untuk avatar */
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
        bottom: -40px; /* Tarik ke bawah setengah dari tinggi avatar */
        left: 50%;
        transform: translateX(-50%);
        border: 4px solid white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }
    .user-card-body {
        padding: 3rem 1.5rem 1.5rem; /* Beri ruang di atas untuk avatar */
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
        width: 24px; /* Agar ikon sejajar */
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
</style>

<?php
require_once 'includes/navbar.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once 'includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-users-cog me-2"></i><?php echo $pageTitle; ?></h1>
                <button type="button" id="btnTambahPengguna" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Tambah Pengguna
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

            <div class="row">
                <?php if (empty($users)): ?>
                    <div class="col">
                        <div class="text-center empty-state-container">
                            <i class="fas fa-user-slash fa-4x text-muted mb-4"></i>
                            <h4 class="text-dark fw-bold">Belum Ada Pengguna</h4>
                            <p class="text-muted">Tekan tombol "Tambah Pengguna" untuk membuat pengguna baru.</p>
                        </div>
                    </div>
                <?php else: 
                    // Palet warna untuk avatar agar lebih menarik
                    $avatarColors = ['#0d6efd', '#6f42c1', '#d63384', '#198754', '#fd7e14', '#dc3545'];
                    $colorIndex = 0;
                    foreach ($users as $user): 
                        $avatarColor = $avatarColors[$colorIndex % count($avatarColors)];
                        $colorIndex++;
                    ?>
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="user-card h-100">
                                <div class="user-card-header">
                                    <div class="user-avatar" style="background-color: <?php echo $avatarColor; ?>;">
                                        <span><?php echo strtoupper(substr($user['nama_lengkap'], 0, 1)); ?></span>
                                    </div>
                                </div>
                                <div class="user-card-body">
                                    <h5 class="user-name"><?php echo htmlspecialchars($user['nama_lengkap']); ?></h5>
                                    <p class="user-role"><?php echo htmlspecialchars($user['jabatan']); ?></p>
                                    <ul class="user-details">
                                        <li><i class="fas fa-user fa-fw"></i><span><?php echo htmlspecialchars($user['username']); ?></span></li>
                                        <li><i class="fas fa-envelope fa-fw"></i><span><?php echo htmlspecialchars($user['email']); ?></span></li>
                                        <li><i class="fas fa-calendar-alt fa-fw"></i><span>Bergabung: <?php echo formatTanggalIndonesia(date('Y-m-d', strtotime($user['created_at']))); ?></span></li>
                                    </ul>
                                </div>
                                <div class="user-card-footer">
                                    <?php if ((int)$_SESSION['admin_id'] !== (int)$user['id']): ?>
                                        <div class="btn-group w-100">
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit" data-id="<?php echo $user['id']; ?>" data-nama_lengkap="<?php echo htmlspecialchars($user['nama_lengkap']); ?>" data-jabatan="<?php echo htmlspecialchars($user['jabatan']); ?>" data-email="<?php echo htmlspecialchars($user['email']); ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>"><i class="fas fa-edit me-2"></i>Edit</button>
                                            <a href="?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Anda yakin ingin menghapus pengguna ini?')"><i class="fas fa-trash"></i></a>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center"><span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Ini Akun Anda</span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="userForm" method="POST" action="users.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">Tambah Pengguna Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="formUserId">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><label for="nama_lengkap" class="form-label">Nama Lengkap <span class="text-danger">*</span></label><input type="text" class="form-control" id="nama_lengkap" name="nama_lengkap" required></div>
                        <div class="col-md-6"><label for="jabatan" class="form-label">Jabatan <span class="text-danger">*</span></label><input type="text" class="form-control" id="jabatan" name="jabatan" required></div>
                    </div>
                    <div class="mb-3"><label for="email" class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="email" name="email" required></div>
                    <hr class="my-4">
                    <div class="row g-3">
                        <div class="col-md-6"><label for="username" class="form-label">Username <span class="text-danger">*</span></label><input type="text" class="form-control" id="username" name="username" required></div>
                        <div class="col-md-6"><label for="password" class="form-label">Password</label><div class="input-group"><input type="password" class="form-control" id="password" name="password"><button class="btn btn-outline-secondary" type="button" id="togglePassword"><i class="fas fa-eye"></i></button></div><small id="passwordHelp" class="form-text text-muted">Wajib diisi untuk pengguna baru.</small></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary">Simpan</button></div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
// --- SCRIPT JAVASCRIPT TIDAK DIUBAH ---
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
    
    function setupAddModal() {
        form.reset();
        modalTitle.textContent = 'Tambah Pengguna Baru';
        submitButton.textContent = 'Simpan';
        submitButton.classList.remove('btn-warning');
        submitButton.classList.add('btn-primary');
        formAction.value = 'create';
        formUserId.value = '';
        passwordInput.setAttribute('required', 'required');
        passwordHelp.textContent = 'Wajib diisi untuk pengguna baru.';
        userModal.show();
    }

    function setupEditModal(button) {
        form.reset();
        const data = button.dataset;
        modalTitle.textContent = 'Edit Data Pengguna';
        submitButton.textContent = 'Update Data';
        submitButton.classList.remove('btn-primary');
        submitButton.classList.add('btn-warning');
        formAction.value = 'update';
        formUserId.value = data.id;
        document.getElementById('nama_lengkap').value = data.nama_lengkap;
        document.getElementById('jabatan').value = data.jabatan;
        document.getElementById('email').value = data.email;
        document.getElementById('username').value = data.username;
        passwordInput.removeAttribute('required');
        passwordHelp.textContent = 'Kosongkan jika tidak ingin mengubah password.';
        userModal.show();
    }

    document.getElementById('btnTambahPengguna').addEventListener('click', setupAddModal);
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
});
</script>

</body>
</html>