<?php
/**
 * File untuk fungsi-fungsi umum
 * Last modified: 12 Juli 2025
 */

/**
 * Memulai sesi hanya jika belum ada yang aktif.
 * Fungsi ini sebaiknya dipanggil di awal setiap halaman yang membutuhkan sesi.
 */
function startSecureSession() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Membuat hash dari password menggunakan algoritma default PHP yang aman.
 * @param string $password Password mentah.
 * @return string Hash dari password.
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Memverifikasi apakah password mentah cocok dengan hash yang ada.
 * @param string $password Password mentah yang diinput pengguna.
 * @param string $hash Hash yang tersimpan di database.
 * @return bool True jika cocok, false jika tidak.
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Membersihkan input dari spasi berlebih, backslash, dan karakter HTML.
 * @param string $data Data input.
 * @return string Data yang sudah bersih.
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Mengunggah file gambar ke direktori server.
 * @param array $file Variabel $_FILES['nama_input'].
 * @param string $targetDir Direktori tujuan relatif dari root proyek.
 * @return string|false Nama file yang berhasil diunggah, atau false jika gagal.
 */
function uploadFile($file, $targetDir = 'assets/uploads/') {
    // Pastikan path absolut ke direktori target benar
    $absoluteTargetDir = dirname(__DIR__) . '/' . $targetDir;
    
    // Buat direktori jika belum ada
    if (!file_exists($absoluteTargetDir)) {
        mkdir($absoluteTargetDir, 0777, true);
    }
    
    // Buat nama file yang unik untuk menghindari penimpaan file
    $fileName = time() . '_' . basename(preg_replace("/[^a-zA-Z0-9.\-_]/", "", $file["name"]));
    $targetFile = $absoluteTargetDir . $fileName;
    
    // Validasi dasar
    if (empty($file["tmp_name"])) return false;

    // Cek apakah file adalah gambar asli
    $check = getimagesize($file["tmp_name"]);
    if($check === false) {
        return false; // Bukan file gambar
    }
    
    // Batasi ukuran file (maksimal 5MB)
    if ($file["size"] > 5 * 1024 * 1024) { // 5MB
        return false;
    }
    
    // Izinkan hanya format gambar tertentu
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $allowedTypes = ["jpg", "png", "jpeg", "gif"];
    if (!in_array($imageFileType, $allowedTypes)) {
        return false;
    }
    
    // Pindahkan file dari temporary location ke direktori tujuan
    if (move_uploaded_file($file["tmp_name"], $targetFile)) {
        return $fileName; // Sukses, kembalikan nama file baru
    } else {
        return false; // Gagal memindahkan file
    }
}

/**
 * Mengubah format tanggal (YYYY-MM-DD) menjadi format Indonesia (DD NamaBulan YYYY).
 * @param string $date Tanggal dalam format YYYY-MM-DD.
 * @return string Tanggal dalam format Indonesia.
 */
function formatTanggalIndonesia($date) {
    if (empty($date) || $date == '0000-00-00') {
        return "Tanggal tidak valid";
    }
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    
    $pecahkan = explode('-', $date);
    // Pastikan format tanggal benar sebelum dipecah
    if (count($pecahkan) != 3) {
        return "Format tanggal salah";
    }
    return $pecahkan[2] . ' ' . $bulan[(int)$pecahkan[1]] . ' ' . $pecahkan[0];
}

/**
 * Mengarahkan pengguna ke URL lain dan menghentikan eksekusi skrip.
 * @param string $url URL tujuan.
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Memeriksa apakah pengguna sudah login sesuai dengan tipenya (user/admin).
 * Jika belum, akan diarahkan ke halaman login.
 * @param string $userType Tipe pengguna ('user' atau 'admin').
 */
function checkLogin($userType = 'user') {
    startSecureSession(); // Memastikan sesi sudah dimulai
    
    $isLoggedIn = false;
    $loginPage = 'login.php';

    if ($userType == 'admin' && isset($_SESSION['admin_id'])) {
        $isLoggedIn = true;
    } elseif ($userType == 'user' && isset($_SESSION['user_id'])) {
        $isLoggedIn = true;
        // Jika file ini ada di dalam subfolder (misal: /user/dashboard.php),
        // path ke login.php harus keluar satu level.
        $loginPage = '../login.php'; 
    }

    if (!$isLoggedIn) {
        redirect($loginPage);
    }
}

/**
 * Menghapus semua data sesi untuk logout.
 */
function logout() {
    startSecureSession(); // Memastikan sesi sudah dimulai
    
    // Kosongkan semua variabel dalam array $_SESSION
    $_SESSION = [];
    
    // Hancurkan sesi di server
    session_destroy();
}

?>