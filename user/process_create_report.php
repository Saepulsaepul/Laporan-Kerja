<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

date_default_timezone_set('Asia/Jakarta');

checkLogin('user');

// Hanya proses jika request method adalah POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getConnection();

    // Simpan data inputan ke session untuk repopulasi form jika terjadi error
    $_SESSION['form_data'] = $_POST;

    $category_id = $_POST['category_id'] ?? '';
    $keterangan = sanitizeInput($_POST['keterangan'] ?? '');
    $tanggal_pelaporan = date('Y-m-d');
    $jam_pelaporan = date('H:i:s');
    
    // Validasi dasar
    if (empty($category_id) || empty($keterangan)) {
        $_SESSION['form_error'] = 'Kolom Kategori dan Keterangan wajib diisi.';
        header('Location: create_report.php');
        exit();
    }

    $error = ''; // Variabel error lokal untuk proses upload
    try {
        $foto_bukti = null;
        if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
            $foto_bukti = uploadFile($_FILES['foto_bukti']);
            if (!$foto_bukti) {
                // Jika upload gagal, set error dan redirect
                $_SESSION['form_error'] = 'Gagal mengupload foto. Pastikan file adalah gambar dan ukuran tidak lebih dari 5MB.';
                header('Location: create_report.php');
                exit();
            }
        }
        
        // Jika tidak ada error sama sekali, lanjutkan ke database
        $stmt = $pdo->prepare("
            INSERT INTO reports (user_id, category_id, keterangan, foto_bukti, tanggal_pelaporan, jam_pelaporan) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$_SESSION['user_id'], $category_id, $keterangan, $foto_bukti, $tanggal_pelaporan, $jam_pelaporan])) {
            // Sukses, hapus data form dari session dan set pesan flash
            unset($_SESSION['form_data'], $_SESSION['form_error']);
            $_SESSION['flash_success'] = 'Laporan Anda telah berhasil dikirim!';
            header('Location: dashboard.php');
            exit();
        } else {
            $_SESSION['form_error'] = 'Terjadi kesalahan. Gagal menyimpan laporan ke database.';
            header('Location: create_report.php');
            exit();
        }

    } catch (Exception $e) {
        $_SESSION['form_error'] = 'Terjadi kesalahan pada sistem. Silakan coba lagi nanti.';
        // Untuk debugging: error_log($e->getMessage());
        header('Location: create_report.php');
        exit();
    }
} else {
    // Jika diakses langsung, redirect ke dashboard
    header('Location: dashboard.php');
    exit();
}
?>