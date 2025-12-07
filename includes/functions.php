<?php
// includes/functions.php

/**
 * Memulai session dengan pengaturan yang aman
 */
function startSecureSession() {
    if (session_status() == PHP_SESSION_NONE) {
        // Pengaturan session yang lebih aman
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.use_strict_mode', 1);
        
        session_start();
        
        // Regenerate session ID untuk mencegah fixation attacks
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
}

/**
 * Sanitasi input untuk mencegah XSS
 */
function sanitizeInput($data) {
    if (empty($data)) {
        return '';
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Sanitasi untuk output ke HTML (lebih ketat)
 */
function sanitizeOutput($data) {
    if (empty($data)) {
        return '';
    }
    
    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Hash password menggunakan bcrypt
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verifikasi password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Redirect dengan header Location
 * Fallback ke JavaScript jika headers sudah dikirim
 */
function redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
        exit();
    } else {
        echo '<script>window.location.href="' . $url . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $url . '"></noscript>';
        exit();
    }
}

/**
 * Check login untuk admin/user dengan path yang benar
 */
function checkLogin($userType = 'user') {
    startSecureSession();
    
    $isLoggedIn = false;
    
    if ($userType == 'admin') {
        // Untuk admin: cek session admin_id
        $isLoggedIn = isset($_SESSION['admin_id']);
        // Login page relatif dari lokasi file
        $loginPage = 'login.php';
        
        // Jika di folder admin, login page ada di root
        if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
            $loginPage = '../login.php';
        }
    } else {
        // Untuk user/pekerja: cek session user_id
        $isLoggedIn = isset($_SESSION['user_id']);
        // Login page relatif dari lokasi file
        $loginPage = 'login.php';
        
        // Jika di folder user, login page ada di root
        if (strpos($_SERVER['PHP_SELF'], '/user/') !== false) {
            $loginPage = '../login.php';
        }
    }
    
    if (!$isLoggedIn) {
        redirect($loginPage);
    }
}

/**
 * Check role spesifik
 */
function checkRole($requiredRole) {
    startSecureSession();
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $requiredRole) {
        if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
            redirect('../login.php');
        } else if (strpos($_SERVER['PHP_SELF'], '/user/') !== false) {
            redirect('../login.php');
        } else {
            redirect('login.php');
        }
    }
}

/**
 * Logout dan bersihkan session
 */
function logout() {
    startSecureSession();
    
    // Hapus semua session variables
    $_SESSION = array();
    
    // Hapus session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Hancurkan session
    session_destroy();
    
    // Redirect ke login page
    redirect('login.php');
}

/**
 * Format tanggal Indonesia lengkap
 * Input: YYYY-MM-DD
 * Output: Senin, 10 Desember 2023
 */
function formatTanggalIndonesia($tanggal) {
    if (!$tanggal || $tanggal == "0000-00-00" || $tanggal == "0000-00-00 00:00:00") {
        return "-";
    }
    
    // Array hari dalam bahasa Indonesia
    $hari = [
        0 => 'Minggu',
        1 => 'Senin', 
        2 => 'Selasa', 
        3 => 'Rabu',
        4 => 'Kamis', 
        5 => 'Jumat', 
        6 => 'Sabtu'
    ];
    
    // Array bulan dalam bahasa Indonesia
    $bulan = [
        1 => 'Januari', 
        2 => 'Februari', 
        3 => 'Maret', 
        4 => 'April',
        5 => 'Mei', 
        6 => 'Juni', 
        7 => 'Juli', 
        8 => 'Agustus',
        9 => 'September', 
        10 => 'Oktober', 
        11 => 'November', 
        12 => 'Desember'
    ];
    
    try {
        // Konversi ke timestamp
        $timestamp = strtotime($tanggal);
        if ($timestamp === false) {
            return $tanggal; // Kembalikan asli jika tidak valid
        }
        
        $hariIndex = date('w', $timestamp); // 0-6
        $tgl = date('j', $timestamp); // 1-31
        $bulanIndex = date('n', $timestamp); // 1-12
        $tahun = date('Y', $timestamp);
        
        return $hari[$hariIndex] . ', ' . $tgl . ' ' . $bulan[$bulanIndex] . ' ' . $tahun;
    } catch (Exception $e) {
        return $tanggal;
    }
}

/**
 * Format tanggal singkat
 * Input: YYYY-MM-DD
 * Output: 10 Des 2023
 */
function formatTanggalSingkat($tanggal) {
    if (!$tanggal || $tanggal == "0000-00-00") {
        return "-";
    }
    
    $bulan = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
    ];
    
    $pecah = explode('-', $tanggal);
    if (count($pecah) < 3) {
        return $tanggal;
    }
    
    $tahun = $pecah[0];
    $bulanIndex = (int)$pecah[1];
    $hari = (int)$pecah[2];
    
    if ($bulanIndex < 1 || $bulanIndex > 12) {
        return $tanggal;
    }
    
    return $hari . ' ' . $bulan[$bulanIndex] . ' ' . $tahun;
}

/**
 * Format waktu dari format MySQL
 * Input: HH:MM:SS
 * Output: HH:MM
 */
function formatWaktu($waktu) {
    if (!$waktu) {
        return "-";
    }
    
    return date('H:i', strtotime($waktu));
}

/**
 * Format tanggal dan waktu lengkap
 * Input: YYYY-MM-DD HH:MM:SS
 * Output: Senin, 10 Desember 2023 14:30
 */
function formatTanggalWaktu($datetime) {
    if (!$datetime) {
        return "-";
    }
    
    $tanggal = formatTanggalIndonesia(substr($datetime, 0, 10));
    $waktu = formatWaktu(substr($datetime, 11, 8));
    
    if ($tanggal === "-") {
        return "-";
    }
    
    return $tanggal . ' ' . $waktu;
}

/**
 * Format mata uang Rupiah
 * Input: 1000000
 * Output: Rp 1.000.000
 */
function formatRupiah($angka) {
    if (empty($angka) && $angka !== 0 && $angka !== '0') {
        return "Rp 0";
    }
    
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

/**
 * Format angka tanpa Rp
 * Input: 1000000
 * Output: 1.000.000
 */
function formatAngka($angka) {
    if (empty($angka) && $angka !== 0 && $angka !== '0') {
        return "0";
    }
    
    return number_format($angka, 0, ',', '.');
}

/**
 * Get nama bulan Indonesia
 * Input: 1-12
 * Output: Januari-Desember
 */
function getNamaBulan($bulan) {
    $bulanArr = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    
    return $bulanArr[$bulan] ?? $bulan;
}

/**
 * Generate random string untuk token/reset password
 */
function generateRandomString($length = 32) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    
    return $randomString;
}

/**
 * Validasi email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validasi nomor telepon Indonesia (minimal 10 digit, maksimal 15 digit)
 */
function validatePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return preg_match('/^[0-9]{10,15}$/', $phone);
}

/**
 * Tampilkan alert message dengan Bootstrap
 */
function showAlert($type, $message) {
    $alertTypes = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info'
    ];
    
    $class = $alertTypes[$type] ?? 'alert-info';
    
    return '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">
                ' . sanitizeOutput($message) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
}

/**
 * Batasi teks dengan maksimum karakter
 */
function limitText($text, $maxLength = 100, $suffix = '...') {
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    
    $text = substr($text, 0, $maxLength);
    $lastSpace = strrpos($text, ' ');
    
    if ($lastSpace !== false) {
        $text = substr($text, 0, $lastSpace);
    }
    
    return $text . $suffix;
}

/**
 * Cek apakah file adalah gambar
 */
function isImage($fileType) {
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    return in_array($fileType, $allowedTypes);
}

/**
 * Upload file dengan validasi
 */
function uploadFile($file, $uploadDir, $allowedTypes = [], $maxSize = 2097152) { // 2MB default
    // Buat direktori jika belum ada
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Validasi error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading file. Error code: ' . $file['error']);
    }
    
    // Validasi size
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large. Maximum size: ' . ($maxSize / 1024 / 1024) . 'MB');
    }
    
    // Validasi type jika ada allowedTypes
    if (!empty($allowedTypes) && !in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed types: ' . implode(', ', $allowedTypes));
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    return $filename;
}

/**
 * Debug helper untuk development
 */
function debug($data, $die = true) {
    echo '<pre>';
    print_r($data);
    echo '</pre>';
    
    if ($die) {
        die();
    }
}

/**
 * Get base URL untuk aplikasi
 */
function baseUrl($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = dirname($_SERVER['SCRIPT_NAME']);
    
    // Hapus trailing slash
    $script = rtrim($script, '/');
    
    return $protocol . '://' . $host . $script . '/' . ltrim($path, '/');
}

// Auto-start session di setiap request
startSecureSession();
?>