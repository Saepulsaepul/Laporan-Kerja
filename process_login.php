<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Hanya proses jika metode request adalah POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_type'])) {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'];
    $form_type = $_POST['form_type'];

    // Validasi input tidak boleh kosong
    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = 'Username dan Password tidak boleh kosong!';
        $_SESSION['login_error_type'] = $form_type;
        redirect('login.php');
        exit();
    }

    try {
        $pdo = getConnection();
        
        if ($form_type == 'admin') {
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && verifyPassword($password, $user['password'])) {
                // Hapus session error jika ada
                unset($_SESSION['login_error'], $_SESSION['login_error_type']);

                // Set session admin
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_nama'] = $user['nama_lengkap'];
                redirect('admin/dashboard.php');
                exit();
            } else {
                $_SESSION['login_error'] = 'Username atau Password Admin salah!';
                $_SESSION['login_error_type'] = 'admin';
                redirect('login.php');
                exit();
            }
        } elseif ($form_type == 'user') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && verifyPassword($password, $user['password'])) {
                 // Hapus session error jika ada
                unset($_SESSION['login_error'], $_SESSION['login_error_type']);

                // Set session user
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['jabatan'] = $user['jabatan'];
                redirect('user/dashboard.php');
                exit();
            } else {
                $_SESSION['login_error'] = 'Username atau Password Anda salah!';
                $_SESSION['login_error_type'] = 'user';
                redirect('login.php');
                exit();
            }
        }
    } catch (Exception $e) {
        // Simpan pesan error ke session dan redirect kembali ke halaman login
        $_SESSION['login_error'] = 'Terjadi gangguan pada sistem. Silakan coba lagi nanti.';
        $_SESSION['login_error_type'] = $form_type;
        redirect('login.php');
        exit();
    }
} else {
    // Jika file diakses langsung, redirect ke halaman login
    redirect('login.php');
    exit();
}
?>