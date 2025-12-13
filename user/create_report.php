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
$jadwal_data = [];
$customer_data = [];

// Ambil data jadwal yang belum dilaporkan - SESUAIKAN DENGAN STRUKTUR DATABASE
try {
    $stmt = $pdo->prepare("
        SELECT 
            j.*,
            c.nama_customer,
            c.nama_perusahaan,
            c.telepon,
            c.alamat,
            s.nama_service,
            s.harga,
            s.deskripsi as deskripsi_service
        FROM jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN services s ON j.service_id = s.id
        WHERE j.pekerja_id = ? 
        AND j.status IN ('Berjalan', 'Menunggu')
        AND j.tanggal <= CURDATE()
        AND j.id NOT IN (SELECT jadwal_id FROM reports WHERE jadwal_id IS NOT NULL AND user_id = ?)
        ORDER BY j.tanggal DESC, j.jam DESC
    ");
    $stmt->execute([$user_id, $user_id]);
    $jadwal_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil semua customer untuk opsi manual - SESUAIKAN DENGAN STRUKTUR
    $stmt = $pdo->prepare("SELECT id, nama_customer, nama_perusahaan, telepon, alamat FROM customers ORDER BY nama_customer");
    $stmt->execute();
    $customer_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Gagal mengambil data: " . $e->getMessage();
    error_log("Error create_report: " . $e->getMessage());
}

// Fungsi helper untuk format tanggal


// Proses form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_id = $_POST['customer_id'] ?? '';
    $jadwal_id = $_POST['jadwal_id'] ?? '';
    $keterangan = trim($_POST['keterangan'] ?? '');
    $bahan_digunakan = trim($_POST['bahan_digunakan'] ?? '');
    $hasil_pengamatan = trim($_POST['hasil_pengamatan'] ?? '');
    $rekomendasi = trim($_POST['rekomendasi'] ?? '');
    $tanggal_pelaporan = $_POST['tanggal_pelaporan'] ?? date('Y-m-d');
    $jam_mulai = $_POST['jam_mulai'] ?? date('H:i');
    $jam_selesai = $_POST['jam_selesai'] ?? date('H:i', strtotime('+1 hour'));
    $rating_customer = $_POST['rating_customer'] ?? 5;
    
    // Validasi
    if (empty($keterangan)) {
        $error = "Keterangan pekerjaan harus diisi!";
    } elseif (empty($jam_mulai) || empty($jam_selesai)) {
        $error = "Jam mulai dan jam selesai harus diisi!";
    } elseif (strtotime($jam_selesai) <= strtotime($jam_mulai)) {
        $error = "Jam selesai harus setelah jam mulai!";
    } else {
        try {
            // Handle upload foto - bukti
            $foto_bukti = null;
            $foto_sebelum = null;
            $foto_sesudah = null;
            
            // Fungsi untuk handle upload
            function uploadFoto($file_key, $prefix, $user_id) {
                if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../assets/uploads/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array($file_ext, $allowed_ext)) {
                        // Cek ukuran file (max 5MB)
                        if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
                            return ['error' => "File terlalu besar! Maksimal 5MB."];
                        }
                        
                        $filename = $prefix . '_' . time() . '_' . $user_id . '.' . $file_ext;
                        $target_path = $upload_dir . $filename;
                        
                        if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $target_path)) {
                            return ['success' => true, 'filename' => $filename];
                        } else {
                            return ['error' => "Gagal mengupload foto!"];
                        }
                    } else {
                        return ['error' => "Format file tidak didukung! Hanya JPG, PNG, GIF, WEBP."];
                    }
                }
                return null;
            }
            
            // Upload foto bukti
            $upload_result = uploadFoto('foto_bukti', 'bukti', $user_id);
            if ($upload_result && isset($upload_result['error'])) {
                $error = $upload_result['error'];
            } elseif ($upload_result && isset($upload_result['filename'])) {
                $foto_bukti = $upload_result['filename'];
            }
            
            // Upload foto sebelum (opsional)
            if (empty($error) && isset($_FILES['foto_sebelum'])) {
                $upload_result = uploadFoto('foto_sebelum', 'sebelum', $user_id);
                if ($upload_result && isset($upload_result['filename'])) {
                    $foto_sebelum = $upload_result['filename'];
                }
            }
            
            // Upload foto sesudah (opsional)
            if (empty($error) && isset($_FILES['foto_sesudah'])) {
                $upload_result = uploadFoto('foto_sesudah', 'sesudah', $user_id);
                if ($upload_result && isset($upload_result['filename'])) {
                    $foto_sesudah = $upload_result['filename'];
                }
            }
            
            if (empty($error)) {
                // Generate kode laporan otomatis
                $kode_laporan = generateKodeLaporan($pdo);
                
                // Insert report sesuai dengan struktur database
                $stmt = $pdo->prepare("
                    INSERT INTO reports 
                    (kode_laporan, user_id, jadwal_id, customer_id, keterangan, 
                     bahan_digunakan, hasil_pengamatan, rekomendasi, 
                     foto_bukti, foto_sebelum, foto_sesudah,
                     tanggal_pelaporan, jam_mulai, jam_selesai, rating_customer)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $kode_laporan,
                    $user_id,
                    !empty($jadwal_id) ? $jadwal_id : null,
                    !empty($customer_id) ? $customer_id : null,
                    $keterangan,
                    $bahan_digunakan,
                    $hasil_pengamatan,
                    $rekomendasi,
                    $foto_bukti,
                    $foto_sebelum,
                    $foto_sesudah,
                    $tanggal_pelaporan,
                    $jam_mulai,
                    $jam_selesai,
                    $rating_customer
                ]);
                
                $report_id = $pdo->lastInsertId();
                
                // Update status jadwal jika berdasarkan jadwal
                if (!empty($jadwal_id)) {
                    $stmt = $pdo->prepare("UPDATE jadwal SET status = 'Selesai' WHERE id = ?");
                    $stmt->execute([$jadwal_id]);
                }
                
                $success = "Laporan berhasil disimpan! Kode: " . $kode_laporan;
                
                // Reset form jika sukses
                $_POST = array();
                
                // Redirect setelah 3 detik
                header("refresh:3;url=my_reports.php");
            }
            
        } catch (PDOException $e) {
            $error = "Gagal menyimpan laporan: " . $e->getMessage();
            error_log("Error save report: " . $e->getMessage());
        }
    }
}

// Fungsi untuk generate kode laporan
function generateKodeLaporan($pdo) {
    $tahun = date('Y');
    $bulan = date('m');
    
    // Cari sequence terakhir untuk bulan ini
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING(kode_laporan, 14) AS UNSIGNED)), 0) + 1 
        FROM reports 
        WHERE kode_laporan LIKE CONCAT('RPT/', ?, '/', ?, '/%')
    ");
    $stmt->execute([$tahun, $bulan]);
    $sequence = $stmt->fetchColumn();
    
    return sprintf('RPT/%s/%s/%03d', $tahun, $bulan, $sequence);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Laporan - Pest Control System</title>
    
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
        
        /* Form Container */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        /* Form Controls */
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border: 1px solid #ced4da;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        /* File Upload */
        .file-upload {
            position: relative;
            overflow: hidden;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 30px 20px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }
        
        .file-upload:hover {
            border-color: var(--primary-color);
            background: rgba(25, 135, 84, 0.05);
        }
        
        .file-upload i {
            font-size: 2.5rem;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .file-upload input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        
        /* Preview Image */
        .image-preview {
            margin-top: 15px;
            text-align: center;
        }
        
        .image-preview img {
            max-width: 100%;
            max-height: 150px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 10px;
        }
        
        /* Jadwal Cards */
        .jadwal-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .jadwal-card:hover {
            border-color: var(--primary-color);
            background: rgba(25, 135, 84, 0.05);
        }
        
        .jadwal-card.selected {
            border-color: var(--primary-color);
            background: rgba(25, 135, 84, 0.1);
            box-shadow: 0 5px 15px rgba(25, 135, 84, 0.1);
        }
        
        .jadwal-radio {
            display: none;
        }
        
        .jadwal-info h6 {
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .jadwal-detail {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        /* Rating Stars */
        .rating-stars {
            font-size: 1.5rem;
            color: #ffc107;
            cursor: pointer;
        }
        
        .rating-stars i {
            margin-right: 5px;
        }
        
        .rating-stars .far {
            color: #e4e5e9;
        }
        
        /* Time Inputs */
        .time-inputs {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .time-inputs .form-control {
            flex: 1;
        }
        
        .time-separator {
            font-weight: bold;
            color: #6c757d;
        }
        
        /* Buttons */
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 30px;
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
            padding: 10px 25px;
            border-radius: 10px;
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
        
        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--dark-color) 0%, #495057 100%);
            color: white;
            padding: 20px 0;
            margin-top: 50px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .time-inputs {
                flex-direction: column;
                gap: 10px;
            }
            
            .time-separator {
                display: none;
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
                            <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                        </div>
                        <div class="d-none d-md-block">
                            <div class="fw-bold"><?php echo htmlspecialchars($user_name); ?></div>
                            <small>Pekerja Pest Control</small>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><h6 class="dropdown-header">Akun Pekerja</h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-home me-2"></i>Dashboard</a></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profil Saya</a></li>
                        <li><a class="dropdown-item" href="my_reports.php"><i class="fas fa-file-alt me-2"></i>Laporan Saya</a></li>
                        <li><a class="dropdown-item" href="my_schedule.php"><i class="fas fa-calendar-alt me-2"></i>Jadwal Saya</a></li>
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
                        <i class="fas fa-plus-circle me-2"></i>Buat Laporan Baru
                    </h1>
                    <p class="lead mb-0">Laporkan hasil pekerjaan pest control yang telah diselesaikan</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="my_reports.php" class="btn btn-outline-custom">
                        <i class="fas fa-arrow-left me-2"></i>Kembali ke Laporan
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

        <!-- Form Laporan -->
        <div class="form-container">
            <form method="POST" action="" enctype="multipart/form-data">
                <!-- Section 1: Sumber Data -->
                <div class="form-section">
                    <h5 class="section-title">
                        <i class="fas fa-database"></i> Sumber Data Laporan
                    </h5>
                    
                    <div class="mb-3">
                        <label class="form-label">Pilih Sumber Data:</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="data_source" id="source_schedule" value="schedule" checked>
                            <label class="form-check-label" for="source_schedule">
                                <i class="fas fa-calendar-check me-1"></i> Dari Jadwal
                            </label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="data_source" id="source_manual" value="manual">
                            <label class="form-check-label" for="source_manual">
                                <i class="fas fa-edit me-1"></i> Manual
                            </label>
                        </div>
                    </div>
                    
                    <!-- Dari Jadwal -->
                    <div id="schedule-section">
                        <?php if (empty($jadwal_data)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Tidak ada jadwal yang perlu dilaporkan. 
                                Semua jadwal sudah dilaporkan atau belum ada jadwal untuk hari ini.
                            </div>
                        <?php else: ?>
                            <label class="form-label mb-3">Pilih Jadwal yang Dilaporkan:</label>
                            <?php foreach ($jadwal_data as $jadwal): 
                                $customer_name = !empty($jadwal['nama_customer']) ? $jadwal['nama_customer'] : $jadwal['nama_perusahaan'];
                            ?>
                                <label class="jadwal-card">
                                    <input type="radio" name="jadwal_id" value="<?php echo $jadwal['id']; ?>" 
                                           class="jadwal-radio" required>
                                    <div class="jadwal-info">
                                        <h6><?php echo htmlspecialchars($customer_name); ?></h6>
                                        <div class="jadwal-detail">
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($jadwal['nama_service']); ?></span>
                                            <span class="mx-2">•</span>
                                            <i class="far fa-calendar me-1"></i>
                                            <?php echo formatTanggalIndonesia($jadwal['tanggal']); ?>
                                            <span class="mx-2">•</span>
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo date('H:i', strtotime($jadwal['jam'])); ?>
                                        </div>
                                        <div class="jadwal-detail mt-1">
                                            <small>
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                <?php echo htmlspecialchars($jadwal['alamat'] ?? 'Tidak ada alamat'); ?>
                                            </small>
                                        </div>
                                        <?php if ($jadwal['deskripsi_service']): ?>
                                        <div class="jadwal-detail mt-1">
                                            <small>
                                                <i class="fas fa-info-circle me-1"></i>
                                                <?php echo htmlspecialchars(substr($jadwal['deskripsi_service'], 0, 100)); ?>...
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Manual Input -->
                    <div id="manual-section" style="display: none;">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Pilih Customer</label>
                                <select name="customer_id" class="form-select" id="customer_select">
                                    <option value="">-- Pilih Customer --</option>
                                    <?php foreach ($customer_data as $customer): 
                                        $customer_display = !empty($customer['nama_customer']) 
                                            ? $customer['nama_customer'] . ' (' . $customer['nama_perusahaan'] . ')'
                                            : $customer['nama_perusahaan'];
                                    ?>
                                        <option value="<?php echo $customer['id']; ?>">
                                            <?php echo htmlspecialchars($customer_display); ?> 
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Section 2: Detail Pekerjaan -->
                <div class="form-section">
                    <h5 class="section-title">
                        <i class="fas fa-clipboard-check"></i> Detail Pekerjaan
                    </h5>
                    
                    <div class="row mb-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Pelaporan <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_pelaporan" class="form-control" 
                                   value="<?php echo $_POST['tanggal_pelaporan'] ?? date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Waktu Pekerjaan <span class="text-danger">*</span></label>
                            <div class="time-inputs">
                                <input type="time" name="jam_mulai" class="form-control" 
                                       value="<?php echo $_POST['jam_mulai'] ?? date('H:i'); ?>" required>
                                <span class="time-separator">-</span>
                                <input type="time" name="jam_selesai" class="form-control" 
                                       value="<?php echo $_POST['jam_selesai'] ?? date('H:i', strtotime('+1 hour')); ?>" required>
                            </div>
                            <small class="text-muted">Isi jam mulai dan selesai pekerjaan</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Keterangan Pekerjaan <span class="text-danger">*</span></label>
                        <textarea name="keterangan" class="form-control" 
                                  placeholder="Deskripsikan hasil pekerjaan yang telah dilakukan, metode yang digunakan, area yang ditangani..." 
                                  required rows="4"><?php echo htmlspecialchars($_POST['keterangan'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Bahan yang Digunakan</label>
                            <textarea name="bahan_digunakan" class="form-control" 
                                      placeholder="Jenis pestisida, konsentrasi, alat yang digunakan..." 
                                      rows="3"><?php echo htmlspecialchars($_POST['bahan_digunakan'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hasil Pengamatan</label>
                            <textarea name="hasil_pengamatan" class="form-control" 
                                      placeholder="Kondisi sebelum dan sesudah, temuan hama, hasil monitoring..." 
                                      rows="3"><?php echo htmlspecialchars($_POST['hasil_pengamatan'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Rekomendasi</label>
                        <textarea name="rekomendasi" class="form-control" 
                                  placeholder="Saran untuk customer, jadwal follow-up, tindakan pencegahan..." 
                                  rows="3"><?php echo htmlspecialchars($_POST['rekomendasi'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- <div class="mb-3">
                        <label class="form-label">Rating Customer (1-5)</label>
                        <div class="rating-stars" id="ratingStars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star" data-value="<?php echo $i; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating_customer" id="ratingInput" value="<?php echo $_POST['rating_customer'] ?? 5; ?>">
                        <small class="text-muted">Klik bintang untuk memberikan rating (opsional)</small>
                    </div> -->
                </div>
                
                <!-- Section 3: Bukti Foto -->
                <div class="form-section">
                    <h5 class="section-title">
                        <i class="fas fa-camera"></i> Bukti Foto
                    </h5>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="file-upload">
                                <i class="fas fa-camera"></i>
                                <h6 class="mb-2">Foto Bukti</h6>
                                <p class="text-muted small">Foto bukti pekerjaan</p>
                                <input type="file" name="foto_bukti" accept="image/*">
                                <div class="image-preview" id="previewBukti"></div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="file-upload">
                                <i class="fas fa-image"></i>
                                <h6 class="mb-2">Foto Sebelum</h6>
                                <p class="text-muted small">Foto kondisi sebelum (opsional)</p>
                                <input type="file" name="foto_sebelum" accept="image/*">
                                <div class="image-preview" id="previewSebelum"></div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="file-upload">
                                <i class="fas fa-images"></i>
                                <h6 class="mb-2">Foto Sesudah</h6>
                                <p class="text-muted small">Foto kondisi sesudah (opsional)</p>
                                <input type="file" name="foto_sesudah" accept="image/*">
                                <div class="image-preview" id="previewSesudah"></div>
                            </div>
                        </div>
                    </div>
                    
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Format: JPG, PNG, GIF, WEBP (Maksimal 5MB per file)
                    </small>
                </div>
                
                <!-- Submit Buttons -->
                <div class="text-center pt-4">
                    <button type="submit" class="btn btn-primary-custom px-5">
                        <i class="fas fa-paper-plane me-2"></i>Simpan Laporan
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-custom ms-3">
                        <i class="fas fa-times me-2"></i>Batal
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    <h5 class="mb-2"><i class="fas fa-bug me-2"></i>Pest Control System</h5>
                    <p class="mb-0">PT. Rexon Mitra Prima - Jasa Pembasmi Hama Profesional</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-phone me-1"></i> 0812-3456-7890
                        <span class="mx-2">•</span>
                        <i class="fas fa-envelope me-1"></i> info@rexonpestcontrol.com
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
        // Toggle antara jadwal dan manual
        const scheduleRadio = document.getElementById('source_schedule');
        const manualRadio = document.getElementById('source_manual');
        const scheduleSection = document.getElementById('schedule-section');
        const manualSection = document.getElementById('manual-section');
        
        scheduleRadio.addEventListener('change', function() {
            scheduleSection.style.display = 'block';
            manualSection.style.display = 'none';
            document.querySelectorAll('#manual-section input, #manual-section select').forEach(el => {
                el.disabled = true;
            });
            document.querySelectorAll('#schedule-section input').forEach(el => {
                el.disabled = false;
                if (el.type === 'radio') el.required = true;
            });
        });
        
        manualRadio.addEventListener('change', function() {
            scheduleSection.style.display = 'none';
            manualSection.style.display = 'block';
            document.querySelectorAll('#schedule-section input').forEach(el => {
                el.disabled = true;
                if (el.type === 'radio') el.required = false;
            });
            document.querySelectorAll('#manual-section input, #manual-section select').forEach(el => {
                el.disabled = false;
            });
        });
        
        // Jadwal card selection
        document.querySelectorAll('.jadwal-radio').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.jadwal-card').forEach(card => {
                    card.classList.remove('selected');
                });
                if (this.checked) {
                    this.parentElement.classList.add('selected');
                }
            });
        });
        
        // Rating stars
        const ratingStars = document.querySelectorAll('#ratingStars .fa-star');
        const ratingInput = document.getElementById('ratingInput');
        
        ratingStars.forEach(star => {
            star.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                ratingInput.value = value;
                
                // Update stars display
                ratingStars.forEach((s, index) => {
                    if (index < value) {
                        s.classList.add('fas');
                        s.classList.remove('far');
                    } else {
                        s.classList.add('far');
                        s.classList.remove('fas');
                    }
                });
            });
        });
        
        // Initialize rating stars
        const initialRating = ratingInput.value;
        ratingStars.forEach((star, index) => {
            if (index < initialRating) {
                star.classList.add('fas');
                star.classList.remove('far');
            } else {
                star.classList.add('far');
                star.classList.remove('fas');
            }
        });
        
        // Image preview for all file inputs
        function setupImagePreview(inputId, previewId) {
            const fileInput = document.querySelector(`input[name="${inputId}"]`);
            const previewContainer = document.getElementById(previewId);
            
            if (fileInput && previewContainer) {
                fileInput.addEventListener('change', function() {
                    const file = this.files[0];
                    
                    if (file) {
                        // Check file size (5MB max)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('File terlalu besar! Maksimal 5MB.');
                            this.value = '';
                            return;
                        }
                        
                        const reader = new FileReader();
                        
                        reader.addEventListener('load', function() {
                            previewContainer.innerHTML = `
                                <img src="${reader.result}" alt="Preview" class="img-thumbnail">
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-preview">
                                        <i class="fas fa-trash me-1"></i>Hapus
                                    </button>
                                </div>
                            `;
                            
                            // Remove preview button
                            previewContainer.querySelector('.remove-preview').addEventListener('click', function() {
                                fileInput.value = '';
                                previewContainer.innerHTML = '';
                            });
                        });
                        
                        reader.readAsDataURL(file);
                    } else {
                        previewContainer.innerHTML = '';
                    }
                });
            }
        }
        
        // Setup previews for all file inputs
        setupImagePreview('foto_bukti', 'previewBukti');
        setupImagePreview('foto_sebelum', 'previewSebelum');
        setupImagePreview('foto_sesudah', 'previewSesudah');
        
        // Set default time to now
        function getCurrentTime() {
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            return `${hours}:${minutes}`;
        }
        
        // Set jam mulai to current time if empty
        const jamMulaiInput = document.querySelector('input[name="jam_mulai"]');
        const jamSelesaiInput = document.querySelector('input[name="jam_selesai"]');
        
        if (jamMulaiInput && !jamMulaiInput.value) {
            jamMulaiInput.value = getCurrentTime();
            
            // Set jam selesai to 1 hour later
            const now = new Date();
            now.setHours(now.getHours() + 1);
            const endHours = now.getHours().toString().padStart(2, '0');
            const endMinutes = now.getMinutes().toString().padStart(2, '0');
            jamSelesaiInput.value = `${endHours}:${endMinutes}`;
        }
    });
    </script>
</body>
</html>