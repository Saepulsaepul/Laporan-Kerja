<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

// Cek login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pekerja') {
    header("Location: ../login.php");
    exit();
}

$pdo = getConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['nama'] ?? 'Pekerja';

// Inisialisasi variabel
$error = '';
$success = '';
$user_data = [];

// Ambil data user dari database
try {
    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE id = ? AND status = 'Aktif'
    ");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        session_destroy();
        header("Location: ../login.php");
        exit();
    }
} catch (PDOException $e) {
    $error = "Gagal mengambil data profil: " . $e->getMessage();
}

// Hitung statistik pekerja
$stats = [
    'total_laporan' => 0,
    'laporan_hari_ini' => 0,
    'laporan_bulan_ini' => 0,
    'jadwal_bulan_ini' => 0,
    'rating_rata' => 0
];

try {
    // Total laporan
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stats['total_laporan'] = $stmt->fetchColumn();
    
    // Laporan hari ini
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND DATE(tanggal_pelaporan) = CURDATE()");
    $stmt->execute([$user_id]);
    $stats['laporan_hari_ini'] = $stmt->fetchColumn();
    
    // Laporan bulan ini
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND MONTH(tanggal_pelaporan) = MONTH(CURDATE()) AND YEAR(tanggal_pelaporan) = YEAR(CURDATE())");
    $stmt->execute([$user_id]);
    $stats['laporan_bulan_ini'] = $stmt->fetchColumn();
    
    // Jadwal bulan ini
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal WHERE pekerja_id = ? AND MONTH(tanggal) = MONTH(CURDATE()) AND YEAR(tanggal) = YEAR(CURDATE())");
    $stmt->execute([$user_id]);
    $stats['jadwal_bulan_ini'] = $stmt->fetchColumn();
    
    // Rating rata-rata
    $stmt = $pdo->prepare("SELECT AVG(rating_customer) FROM reports WHERE user_id = ? AND rating_customer > 0");
    $stmt->execute([$user_id]);
    $rating = $stmt->fetchColumn();
    $stats['rating_rata'] = $rating ? round($rating, 1) : 0;
    
} catch (PDOException $e) {
    error_log("Error statistik profil: " . $e->getMessage());
}

// Proses update profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telepon = trim($_POST['telepon'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validasi input
    if (empty($nama)) {
        $error = "Nama tidak boleh kosong";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($email)) {
        $error = "Email tidak valid";
    } else {
        try {
            // Cek apakah email sudah digunakan oleh user lain
            if (!empty($email)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) {
                    $error = "Email sudah digunakan oleh user lain";
                }
            }
            
            // Jika tidak ada error, update profil
            if (empty($error)) {
                $update_data = [
                    'nama' => $nama,
                    'email' => $email,
                    'telepon' => $telepon,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                // Jika password diisi, update password
                if (!empty($password)) {
                    if ($password !== $confirm_password) {
                        $error = "Konfirmasi password tidak sesuai";
                    } elseif (strlen($password) < 6) {
                        $error = "Password minimal 6 karakter";
                    } else {
                        $update_data['password'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                }
                
                // Update ke database
                if (empty($error)) {
                    $fields = [];
                    $values = [];
                    foreach ($update_data as $field => $value) {
                        $fields[] = "$field = ?";
                        $values[] = $value;
                    }
                    $values[] = $user_id;
                    
                    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                    
                    // Update session
                    $_SESSION['nama'] = $nama;
                    
                    $success = "Profil berhasil diperbarui!";
                    
                    // Refresh data user
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
            
        } catch (PDOException $e) {
            $error = "Gagal memperbarui profil: " . $e->getMessage();
        }
    }
}

// Fungsi untuk menampilkan rating stars
function displayRating($rating) {
    $html = '';
    $rating = (int)$rating;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star text-warning"></i>';
        } else {
            $html .= '<i class="far fa-star text-muted"></i>';
        }
    }
    return $html;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Pest Control System</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #198754;
            --secondary-color: #20c997;
            --accent-color: #0d6efd;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            color: #333;
        }
        
        /* Navbar */
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 4px 20px rgba(25, 135, 84, 0.2);
            padding: 15px 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: white !important;
            display: flex;
            align-items: center;
        }
        
        .navbar-brand i {
            margin-right: 10px;
            font-size: 1.8rem;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: white;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            margin-right: 10px;
        }
        
        /* Header */
        .page-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(25, 135, 84, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(25, 135, 84, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 700;
            margin-right: 25px;
            box-shadow: 0 10px 20px rgba(25, 135, 84, 0.2);
            border: 5px solid white;
        }
        
        .profile-info h2 {
            color: var(--dark-color);
            margin-bottom: 5px;
            font-weight: 700;
        }
        
        .profile-info .badge {
            font-size: 0.85rem;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stats-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary-color);
        }
        
        .stats-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Form Card */
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
        }
        
        .form-section {
            margin-bottom: 25px;
        }
        
        .form-section-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
        }
        
        .form-section-title i {
            margin-right: 10px;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
        }
        
        /* Buttons */
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(25, 135, 84, 0.3);
            color: white;
        }
        
        .btn-outline-custom {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            background: transparent;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-custom:hover {
            background: var(--primary-color);
            color: white;
        }
        
        /* Alert */
        .alert-custom {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        /* Info List */
        .info-list {
            list-style: none;
            padding: 0;
        }
        
        .info-list li {
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
        }
        
        .info-list li:last-child {
            border-bottom: none;
        }
        
        .info-list i {
            width: 30px;
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
            min-width: 150px;
        }
        
        .info-value {
            color: #6c757d;
        }
        
        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--dark-color) 0%, #495057 100%);
            color: white;
            padding: 20px 0;
            margin-top: 50px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-avatar {
                margin-right: 0;
                margin-bottom: 20px;
            }
            
            .info-list li {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .info-label {
                min-width: auto;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-bug"></i>
                Pest Control
            </a>
            
            <div class="d-flex align-items-center">
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" 
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user_data['nama'] ?? $user_name, 0, 1)); ?>
                        </div>
                        <div class="d-none d-md-block">
                            <div class="fw-bold"><?php echo htmlspecialchars($user_data['nama'] ?? $user_name); ?></div>
                            <small>Pekerja Pest Control</small>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><h6 class="dropdown-header">Akun Pekerja</h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-home me-2"></i>Dashboard</a></li>
                        <li><a class="dropdown-item active" href="profile.php"><i class="fas fa-user me-2"></i>Profil Saya</a></li>
                        <li><a class="dropdown-item" href="my_reports.php"><i class="fas fa-file-alt me-2"></i>Laporan Saya</a></li>
                        <li><a class="dropdown-item" href="my_schedule.php"><i class="fas fa-calendar-alt me-2"></i>Jadwal Saya</a></li>
                        <li><a class="dropdown-item" href="create_report.php"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Keluar</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <!-- Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-6 fw-bold text-success mb-2">
                        <i class="fas fa-user me-2"></i>Profil Saya
                    </h1>
                    <p class="lead mb-0">Kelola informasi profil dan akun Anda</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="dashboard.php" class="btn btn-outline-custom">
                        <i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Kolom Kiri: Profil dan Statistik -->
            <div class="col-lg-4 mb-4">
                <!-- Profile Card -->
                <div class="profile-card mb-4">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($user_data['nama'] ?? '', 0, 1)); ?>
                        </div>
                        <div class="profile-info">
                            <h2><?php echo htmlspecialchars($user_data['nama'] ?? ''); ?></h2>
                            <span class="badge bg-success"><?php echo htmlspecialchars($user_data['jabatan'] ?? 'Pekerja Pest Control'); ?></span>
                            
                        </div>
                    </div>
                    
                    <ul class="info-list">
                        <li>
                            <i class="fas fa-user-tag"></i>
                            <span class="info-label">Username:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user_data['username'] ?? ''); ?></span>
                        </li>
                        <li>
                            <i class="fas fa-envelope"></i>
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user_data['email'] ?? '-'); ?></span>
                        </li>
                        <li>
                            <i class="fas fa-phone"></i>
                            <span class="info-label">Telepon:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user_data['telepon'] ?? '-'); ?></span>
                        </li>
                        <li>
                            <i class="fas fa-calendar-alt"></i>
                            <span class="info-label">Bergabung:</span>
                            <span class="info-value"><?php echo date('d F Y', strtotime($user_data['created_at'] ?? '')); ?></span>
                        </li>
                        <li>
                            <i class="fas fa-badge-check"></i>
                            <span class="info-label">Status:</span>
                            <span class="info-value">
                                <span class="badge bg-<?php echo ($user_data['status'] ?? '') == 'Aktif' ? 'success' : 'danger'; ?>">
                                    <?php echo htmlspecialchars($user_data['status'] ?? ''); ?>
                                </span>
                            </span>
                        </li>
                    </ul>
                </div>
                
                <!-- Statistik -->
                <div class="row">
                    <div class="col-md-6 col-sm-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="stats-value"><?php echo $stats['total_laporan']; ?></div>
                            <div class="stats-label">Total Laporan</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-sm-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <div class="stats-value"><?php echo $stats['laporan_hari_ini']; ?></div>
                            <div class="stats-label">Hari Ini</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-sm-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-calendar-week"></i>
                            </div>
                            <div class="stats-value"><?php echo $stats['laporan_bulan_ini']; ?></div>
                            <div class="stats-label">Bulan Ini</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-sm-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <div class="stats-value"><?php echo $stats['jadwal_bulan_ini']; ?></div>
                            <div class="stats-label">Jadwal Bulan Ini</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Kolom Kanan: Form Edit Profil -->
            <div class="col-lg-8">
                <div class="form-card">
                    <form method="POST" action="">
                        <!-- Informasi Pribadi -->
                        <div class="form-section">
                            <h4 class="form-section-title">
                                <i class="fas fa-user-edit"></i>Informasi Pribadi
                            </h4>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nama Lengkap</label>
                                    <input type="text" name="nama" class="form-control" 
                                           value="<?php echo htmlspecialchars($user_data['nama'] ?? ''); ?>" 
                                           required>
                                    <small class="text-muted">Nama lengkap Anda</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" 
                                           disabled>
                                    <small class="text-muted">Username tidak dapat diubah</small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                                    <small class="text-muted">Alamat email aktif</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nomor Telepon</label>
                                    <input type="text" name="telepon" class="form-control" 
                                           value="<?php echo htmlspecialchars($user_data['telepon'] ?? ''); ?>">
                                    <small class="text-muted">Nomor WhatsApp/Telepon</small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Jabatan</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo htmlspecialchars($user_data['jabatan'] ?? ''); ?>" 
                                           disabled>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status Akun</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo htmlspecialchars($user_data['status'] ?? ''); ?>" 
                                           disabled>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Ubah Password -->
                        <div class="form-section">
                            <h4 class="form-section-title">
                                <i class="fas fa-key"></i>Ubah Password
                            </h4>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Kosongkan jika tidak ingin mengubah password
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password Baru</label>
                                    <input type="password" name="password" class="form-control">
                                    <small class="text-muted">Minimal 6 karakter</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Konfirmasi Password</label>
                                    <input type="password" name="confirm_password" class="form-control">
                                    <small class="text-muted">Ulangi password baru</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tombol Aksi -->
                        <div class="form-section">
                            <div class="d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-outline-custom">
                                    <i class="fas fa-times me-2"></i>Batal
                                </a>
                                <button type="submit" class="btn btn-primary-custom">
                                    <i class="fas fa-save me-2"></i>Simpan Perubahan
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Informasi Sistem -->
                <div class="form-card mt-4">
                    <div class="form-section">
                        <h4 class="form-section-title">
                            <i class="fas fa-info-circle"></i>Informasi Sistem
                        </h4>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="alert alert-light border">
                                    <h6><i class="fas fa-laptop me-2"></i>Akses Sistem</h6>
                                    <p class="mb-1 small">Terakhir login: <strong><?php echo date('d F Y H:i'); ?></strong></p>
                                    <p class="mb-0 small">IP Address: <strong><?php echo $_SERVER['REMOTE_ADDR'] ?? 'Tidak diketahui'; ?></strong></p>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <div class="alert alert-light border">
                                    <h6><i class="fas fa-shield-alt me-2"></i>Keamanan</h6>
                                    <p class="mb-1 small">Pastikan password Anda kuat</p>
                                    <p class="mb-0 small">Jangan bagikan akun dengan orang lain</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Perhatian:</strong> Perubahan yang Anda lakukan akan langsung tersimpan di sistem. Pastikan data yang Anda input sudah benar.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    <h5 class="mb-2"><i class="fas fa-bug me-2"></i>Pest Control System</h5>
                    <p class="mb-0">Sistem Manajemen Laporan Pest Control</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-calendar me-1"></i> <?php echo date('d F Y'); ?>
                        <span class="mx-2">•</span>
                        <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($user_data['nama'] ?? ''); ?>
                    </p>
                    <small>&copy; <?php echo date('Y'); ?> All rights reserved.</small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle password visibility
        const togglePassword = (inputId) => {
            const input = document.getElementById(inputId);
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
        };
        
        // Password strength indicator
        const passwordInput = document.querySelector('input[name="password"]');
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                const strength = checkPasswordStrength(password);
                updateStrengthIndicator(strength);
            });
        }
        
        function checkPasswordStrength(password) {
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength++;
            
            // Contains lowercase
            if (/[a-z]/.test(password)) strength++;
            
            // Contains uppercase
            if (/[A-Z]/.test(password)) strength++;
            
            // Contains numbers
            if (/\d/.test(password)) strength++;
            
            // Contains special characters
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            return strength;
        }
        
        function updateStrengthIndicator(strength) {
            const indicator = document.getElementById('password-strength');
            if (!indicator) return;
            
            let text = '';
            let color = '';
            
            switch(strength) {
                case 0:
                case 1:
                    text = 'Sangat Lemah';
                    color = 'danger';
                    break;
                case 2:
                    text = 'Lemah';
                    color = 'warning';
                    break;
                case 3:
                    text = 'Sedang';
                    color = 'info';
                    break;
                case 4:
                    text = 'Kuat';
                    color = 'success';
                    break;
                case 5:
                    text = 'Sangat Kuat';
                    color = 'success';
                    break;
            }
            
            indicator.innerHTML = `<span class="text-${color}">${text}</span>`;
        }
        
        // Form validation
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const password = document.querySelector('input[name="password"]').value;
                const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
                
                if (password && password !== confirmPassword) {
                    e.preventDefault();
                    alert('Konfirmasi password tidak sesuai!');
                    document.querySelector('input[name="confirm_password"]').focus();
                }
                
                if (password && password.length < 6) {
                    e.preventDefault();
                    alert('Password minimal 6 karakter!');
                    document.querySelector('input[name="password"]').focus();
                }
            });
        }
    });
    </script>
</body>
</html>