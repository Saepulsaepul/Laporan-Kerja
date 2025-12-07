<?php
// Mulai session untuk membaca pesan error dari process_login.php
session_start();

$error = '';
$error_type = '';

// Cek apakah ada pesan error di session
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    $error_type = $_SESSION['login_error_type'] ?? '';

    // Hapus session setelah ditampilkan agar tidak muncul lagi
    unset($_SESSION['login_error']);
    unset($_SESSION['login_error_type']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Rexon </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

    <div class="login-container">
        <div class="login-card">
            <button type="button" class="btn btn-sm admin-login-btn" data-bs-toggle="modal" data-bs-target="#adminLoginModal">
                <i class="fas fa-user-shield me-1"></i> Login Admin
            </button>

            <div class="login-card-header">
                <div class="logo-placeholder">
                    <img src="assets/img/hama.png" alt="Logo Dinas Perhubungan" height="300" width="300" >
                </div>
                <h1 class="header-title">Layanan Pest Control </h1>
                <p class="header-subtitle">Kota Tangerang</p>
                <p class="header-system-title">Sistem Laporan Kegiatan</p>
            </div>

            <div class="login-card-body">
                <?php if ($error && $error_type == 'user'): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="process_login.php" novalidate>
                    <input type="hidden" name="form_type" value="user">
                    <div class="mb-3">
                        <label for="user_username" class="form-label"><i class="fas fa-user"></i>Username Petugas</label>
                        <input type="text" class="form-control form-control-lg" id="user_username" name="username" placeholder="Masukkan username Anda" required>
                    </div>
                    <div class="mb-4">
                        <label for="user_password" class="form-label"><i class="fas fa-lock"></i>Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control form-control-lg" id="user_password" name="password" placeholder="Masukkan password Anda" required>
                            <span class="input-group-text" id="toggleUserPassword"><i class="fas fa-eye"></i></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-lg w-100">MASUK</button>
                </form>
            </div>
            
            <div class="footer-text">
                Mengalami kendala? Hubungi Administrator Sistem.
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="adminLoginModal" tabindex="-1" aria-labelledby="adminLoginModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="adminLoginModalLabel"><i class="fas fa-user-shield me-2"></i> Login Administrator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if ($error && $error_type == 'admin'): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="process_login.php" novalidate>
                        <input type="hidden" name="form_type" value="admin">
                        <div class="mb-3">
                            <label for="admin_username" class="form-label">Username Admin</label>
                            <input type="text" class="form-control" id="admin_username" name="username" placeholder="Username admin" required>
                        </div>
                        <div class="mb-3">
                            <label for="admin_password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="admin_password" name="password" placeholder="Password admin" required>
                                <span class="input-group-text" id="toggleAdminPassword"><i class="fas fa-eye"></i></span>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-dark w-100 mt-3">LOGIN SEBAGAI ADMIN</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/login.js"></script>

    <?php if ($error && $error_type == 'admin'): ?>
    <script>
        // Pastikan DOM sudah dimuat sebelum menjalankan skrip modal
        document.addEventListener('DOMContentLoaded', function() {
            var adminModal = new bootstrap.Modal(document.getElementById('adminLoginModal'));
            adminModal.show();
        });
    </script>
    <?php endif; ?>
</body>
</html>