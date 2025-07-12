<?php
session_start(); 

require_once '../includes/functions.php';
require_once '../config/database.php';

header('Content-Type: application/json');

// 1. Pengecekan sesi disesuaikan dengan $_SESSION['admin_id']
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Sesi admin tidak valid.']);
    exit;
}

$newUsername = $_POST['new_username'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
// 2. Mengambil ID admin dari session yang benar
$adminId = $_SESSION['admin_id'];

// Validasi input
if (empty($newUsername) || empty($newPassword) || empty($confirmPassword)) {
    echo json_encode(['success' => false, 'message' => 'Semua field wajib diisi.']);
    exit;
}
if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Password baru dan konfirmasi password tidak cocok.']);
    exit;
}

try {
    $pdo = getConnection();
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    // 3. Query diubah untuk menargetkan tabel 'admin_users' dan WHERE clause disederhanakan
    $stmt = $pdo->prepare("UPDATE admin_users SET username = ?, password = ? WHERE id = ?");
    
    $stmt->execute([$newUsername, $hashedPassword, $adminId]);

    if ($stmt->rowCount() > 0) {
        // Hancurkan sesi agar admin harus login ulang
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Data berhasil diperbarui. Anda akan dialihkan ke halaman login.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui data. Tidak ada data yang berubah atau pengguna tidak ditemukan.']);
    }

} catch (PDOException $e) {
    if ($e->getCode() == 23000) { // Error untuk duplicate entry/username
        echo json_encode(['success' => false, 'message' => 'Username baru sudah digunakan. Silakan pilih username lain.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error database: ' . $e->getMessage()]);
    }
}
?>