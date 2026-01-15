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
$services_data = [];

// Ambil data jadwal yang belum selesai atau perlu kunjungan berikutnya
try {
    // Query untuk jadwal aktif pekerja
    $stmt = $pdo->prepare("
        SELECT 
            j.*,
            j.id as jadwal_id,
            c.nama_customer,
            c.nama_perusahaan,
            c.telepon as customer_telepon,
            c.alamat,
            c.gedung,
            c.lantai,
            c.unit,
            c.jumlah_station,
            s.nama_service,
            s.kode_service,
            s.harga,
            s.deskripsi as deskripsi_service,
            -- Hitung laporan yang sudah dibuat untuk jadwal ini
            (SELECT COUNT(*) FROM reports r WHERE r.jadwal_id = j.id) as total_laporan_dibuat,
            -- Ambil station yang sudah dilaporkan
            (SELECT GROUP_CONCAT(DISTINCT station_id) FROM reports WHERE jadwal_id = j.id AND station_id IS NOT NULL) as reported_stations
        FROM jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN services s ON j.service_id = s.id
        WHERE j.pekerja_id = ? 
        AND j.status IN ('Berjalan', 'Menunggu')
        AND j.tanggal <= CURDATE()
        AND (
            -- Untuk jadwal sekali dengan station: belum semua station dilaporkan
            (j.jenis_periode = 'Sekali' AND c.jumlah_station > 0 AND NOT (
                SELECT COUNT(DISTINCT station_id) = c.jumlah_station 
                FROM reports 
                WHERE jadwal_id = j.id AND station_id IS NOT NULL
            ))
            OR
            -- Untuk jadwal sekali tanpa station: belum ada laporan sama sekali
            (j.jenis_periode = 'Sekali' AND c.jumlah_station = 0 AND NOT EXISTS (
                SELECT 1 FROM reports WHERE jadwal_id = j.id
            ))
            OR
            -- Untuk jadwal berulang: kunjungan_berjalan < jumlah_kunjungan
            (j.jenis_periode != 'Sekali' AND j.kunjungan_berjalan < j.jumlah_kunjungan)
        )
        ORDER BY 
            CASE j.prioritas
                WHEN 'Darurat' THEN 1
                WHEN 'Tinggi' THEN 2
                WHEN 'Sedang' THEN 3
                WHEN 'Rendah' THEN 4
                ELSE 5
            END,
            j.tanggal ASC, 
            j.jam ASC
    ");
    $stmt->execute([$user_id]);
    $jadwal_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Untuk jadwal dengan station, ambil data station
    foreach ($jadwal_data as &$jadwal) {
        if ($jadwal['jumlah_station'] > 0 && $jadwal['jenis_periode'] == 'Sekali') {
            $stmt = $pdo->prepare("
                SELECT 
                    station_number,
                    nama_station,
                    lokasi,
                    status,
                    -- Cek apakah sudah dilaporkan
                    EXISTS (
                        SELECT 1 FROM reports r 
                        WHERE r.jadwal_id = ? 
                        AND r.station_id = stations.station_number
                    ) as sudah_dilaporkan
                FROM stations 
                WHERE customer_id = ? 
                ORDER BY station_number ASC
            ");
            $stmt->execute([$jadwal['jadwal_id'], $jadwal['customer_id']]);
            $jadwal['stations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse reported stations
            $reported_stations = $jadwal['reported_stations'] ? explode(',', $jadwal['reported_stations']) : [];
            $jadwal['reported_stations_array'] = array_map('intval', $reported_stations);
        }
    }
    unset($jadwal);

    // Ambil semua customer untuk opsi manual
    $stmt = $pdo->prepare("
        SELECT id, nama_customer, nama_perusahaan, telepon, alamat, jumlah_station 
        FROM customers 
        WHERE status = 'Aktif' 
        ORDER BY nama_customer
    ");
    $stmt->execute();
    $customer_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Gagal mengambil data: " . $e->getMessage();
    error_log("Error create_report: " . $e->getMessage());
}

// AJAX Handler - Ambil layanan berdasarkan customer
if (isset($_GET['action']) && $_GET['action'] == 'get_services' && isset($_GET['customer_id'])) {
    $customer_id = (int)$_GET['customer_id'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.kode_service, s.nama_service, s.harga
            FROM services s
            INNER JOIN customer_services cs ON s.id = cs.service_id
            WHERE cs.customer_id = ? 
            AND cs.status = 'Aktif'
            AND s.status = 'Aktif'
            ORDER BY s.nama_service
        ");
        $stmt->execute([$customer_id]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($services);
        exit();
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Gagal mengambil data layanan']);
        exit();
    }
}

// Proses form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_id = $_POST['customer_id'] ?? '';
    $jadwal_id = $_POST['jadwal_id'] ?? '';
    $service_id = $_POST['service_id'] ?? '';
    $keterangan = trim($_POST['keterangan'] ?? '');
    $bahan_digunakan = trim($_POST['bahan_digunakan'] ?? '');
    $hasil_pengamatan = trim($_POST['hasil_pengamatan'] ?? '');
    $rekomendasi = trim($_POST['rekomendasi'] ?? '');
    $tanggal_pelaporan = $_POST['tanggal_pelaporan'] ?? date('Y-m-d');
    $jam_mulai = $_POST['jam_mulai'] ?? date('H:i');
    $jam_selesai = $_POST['jam_selesai'] ?? date('H:i', strtotime('+1 hour'));
    $rating_customer = $_POST['rating_customer'] ?? 5;
    
    // Field untuk station inspeksi
    $station_id = isset($_POST['station_id']) ? (int)$_POST['station_id'] : null;
    $station_nama = trim($_POST['station_nama'] ?? '');
    
    // Validasi
    if (empty($keterangan)) {
        $error = "Keterangan pekerjaan harus diisi!";
    } elseif (empty($jam_mulai) || empty($jam_selesai)) {
        $error = "Jam mulai dan jam selesai harus diisi!";
    } elseif (strtotime($jam_selesai) <= strtotime($jam_mulai)) {
        $error = "Jam selesai harus setelah jam mulai!";
    } else {
        try {
            $pdo->beginTransaction();
            
            $nomor_kunjungan = 1;
            $selected_jadwal = null;
            $jumlah_station = 0;
            
            if (!empty($jadwal_id)) {
                // AMBIL data jadwal dengan informasi station
                $stmt = $pdo->prepare("
                    SELECT 
                        j.*, 
                        c.jumlah_station,
                        c.nama_perusahaan,
                        s.nama_service,
                        (SELECT COALESCE(MAX(nomor_kunjungan), 0) FROM reports WHERE jadwal_id = j.id) as last_kunjungan
                    FROM jadwal j
                    LEFT JOIN customers c ON j.customer_id = c.id
                    LEFT JOIN services s ON j.service_id = s.id
                    WHERE j.id = ?
                ");
                $stmt->execute([$jadwal_id]);
                $selected_jadwal = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($selected_jadwal) {
                    $customer_id = $selected_jadwal['customer_id'];
                    $service_id = $selected_jadwal['service_id'];
                    $nomor_kunjungan = ($selected_jadwal['last_kunjungan'] ?? 0) + 1;
                    $jumlah_station = $selected_jadwal['jumlah_station'] ?? 0;
                    
                    // Validasi station
                    if ($station_id && $station_id > 0) {
                        if ($selected_jadwal['jenis_periode'] != 'Sekali') {
                            throw new Exception("Station hanya bisa dipilih untuk jadwal sekali!");
                        }
                        
                        if ($station_id > $jumlah_station) {
                            throw new Exception("Nomor station melebihi jumlah station yang tersedia!");
                        }
                        
                        // Cek apakah station sudah dilaporkan
                        $stmt_check = $pdo->prepare("
                            SELECT COUNT(*) as sudah_dilaporkan 
                            FROM reports 
                            WHERE jadwal_id = ? 
                            AND station_id = ?
                        ");
                        $stmt_check->execute([$jadwal_id, $station_id]);
                        $check_result = $stmt_check->fetch(PDO::FETCH_ASSOC);
                        
                        if ($check_result['sudah_dilaporkan'] > 0) {
                            throw new Exception("Station #{$station_id} sudah dilaporkan sebelumnya!");
                        }
                        
                        // Ambil nama station dari database jika kosong
                        if (empty($station_nama)) {
                            $stmt_station = $pdo->prepare("
                                SELECT nama_station FROM stations 
                                WHERE customer_id = ? AND station_number = ?
                            ");
                            $stmt_station->execute([$customer_id, $station_id]);
                            $station_info = $stmt_station->fetch(PDO::FETCH_ASSOC);
                            if ($station_info) {
                                $station_nama = $station_info['nama_station'];
                            }
                        }
                    }
                    
                    // Validasi khusus untuk jadwal dengan station
                    if ($selected_jadwal['jenis_periode'] == 'Sekali' && $jumlah_station > 0 && !$station_id) {
                        throw new Exception("Pilih salah satu station untuk jadwal ini!");
                    }
                }
            }
            
            // Handle upload foto
            $foto_bukti = null;
            $foto_sebelum = null;
            $foto_sesudah = null;
            
            function uploadFoto($file_key, $prefix, $user_id) {
                if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../assets/uploads/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array($file_ext, $allowed_ext)) {
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
                
                // INSERT laporan dengan station
                $stmt = $pdo->prepare("
                    INSERT INTO reports 
                    (kode_laporan, user_id, jadwal_id, customer_id, service_id, nomor_kunjungan,
                     keterangan, bahan_digunakan, hasil_pengamatan, rekomendasi, 
                     foto_bukti, foto_sebelum, foto_sesudah,
                     tanggal_pelaporan, jam_mulai, jam_selesai, rating_customer,
                     station_id, station_nama) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $kode_laporan,
                    $user_id,
                    !empty($jadwal_id) ? $jadwal_id : null,
                    !empty($customer_id) ? $customer_id : null,
                    !empty($service_id) ? $service_id : null,
                    $nomor_kunjungan,
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
                    $rating_customer,
                    $station_id,
                    $station_nama
                ]);
                
                $report_id = $pdo->lastInsertId();
                
                // Update status jadwal
                if (!empty($jadwal_id) && $selected_jadwal) {
                    $is_single_schedule = ($selected_jadwal['jenis_periode'] == 'Sekali');
                    $has_stations = ($jumlah_station > 0);
                    
                    if ($is_single_schedule && $has_stations) {
                        // Jadwal sekali dengan station
                        // Update kunjungan_berjalan = jumlah station yang sudah dilaporkan
                        $stmt = $pdo->prepare("
                            UPDATE jadwal 
                            SET kunjungan_berjalan = (
                                SELECT COUNT(DISTINCT station_id) 
                                FROM reports 
                                WHERE jadwal_id = ? AND station_id IS NOT NULL
                            ),
                            station_terakhir = ?,
                            total_station_selesai = (
                                SELECT COUNT(DISTINCT station_id) 
                                FROM reports 
                                WHERE jadwal_id = ? AND station_id IS NOT NULL
                            ),
                            updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $jadwal_id,
                            $station_id,
                            $jadwal_id,
                            $jadwal_id
                        ]);
                        
                        // Cek apakah semua station sudah dilaporkan
                        $stmt_check = $pdo->prepare("
                            SELECT COUNT(DISTINCT station_id) as reported_count 
                            FROM reports 
                            WHERE jadwal_id = ? AND station_id IS NOT NULL
                        ");
                        $stmt_check->execute([$jadwal_id]);
                        $check_result = $stmt_check->fetch(PDO::FETCH_ASSOC);
                        
                        if ($check_result['reported_count'] >= $jumlah_station) {
                            // Semua station selesai
                            $stmt = $pdo->prepare("
                                UPDATE jadwal 
                                SET status = 'Selesai', 
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$jadwal_id]);
                        }
                    } elseif ($is_single_schedule && !$has_stations) {
                        // Jadwal sekali tanpa station
                        $stmt = $pdo->prepare("
                            UPDATE jadwal 
                            SET status = 'Selesai', 
                                kunjungan_berjalan = 1,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$jadwal_id]);
                    } else {
                        // Jadwal berulang
                        $stmt = $pdo->prepare("
                            UPDATE jadwal 
                            SET kunjungan_berjalan = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$nomor_kunjungan, $jadwal_id]);
                        
                        // Cek apakah ini kunjungan terakhir
                        if ($nomor_kunjungan >= $selected_jadwal['jumlah_kunjungan']) {
                            $stmt = $pdo->prepare("
                                UPDATE jadwal 
                                SET status = 'Selesai', 
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$jadwal_id]);
                        }
                    }
                }
                
                $pdo->commit();
                
                // Pesan sukses
                $success_message = "✅ Laporan berhasil disimpan!";
                $success_message .= "<br><strong>Kode:</strong> " . $kode_laporan;
                $success_message .= "<br><strong>Kunjungan:</strong> ke-" . $nomor_kunjungan;
                
                if ($station_id) {
                    $success_message .= "<br><strong>Station:</strong> " . ($station_nama ?: "Station #" . $station_id);
                }
                
                if ($selected_jadwal) {
                    $success_message .= "<br><strong>Customer:</strong> " . $selected_jadwal['nama_perusahaan'];
                    $success_message .= "<br><strong>Layanan:</strong> " . $selected_jadwal['nama_service'];
                }
                
                $success = $success_message;
                
                // Reset form jika sukses
                $_POST = array();
                
                // Redirect ke dashboard setelah 5 detik
                header("refresh:5;url=dashboard.php");
                
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Gagal menyimpan laporan: " . $e->getMessage();
            error_log("Error save report: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
            error_log("Error save report: " . $e->getMessage());
        }
    }
}

// Fungsi untuk generate kode laporan
function generateKodeLaporan($pdo) {
    $tahun = date('Y');
    $bulan = date('m');
    
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
        /* Styling untuk station info */
        .station-info {
            background: #e7f5ff;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            border-left: 4px solid #0d6efd;
        }
        
        .station-selector {
            margin-top: 10px;
        }
        
        .station-badge {
            background-color: #e3f2fd;
            color: #1565c0;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
        }
        
        .station-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .station-card:hover {
            border-color: #0d6efd;
            background: rgba(13, 110, 253, 0.05);
        }
        
        .station-card.selected {
            border-color: #0d6efd;
            background: rgba(13, 110, 253, 0.1);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.1);
        }
        
        .station-card.reported {
            opacity: 0.6;
            background: #f1f3f4;
            cursor: not-allowed;
        }
        
        .station-card.reported:hover {
            border-color: #e9ecef;
            background: #f1f3f4;
        }
        
        .station-card input[disabled] {
            cursor: not-allowed;
        }
        
        .station-card label {
            cursor: pointer;
            display: block;
            width: 100%;
            height: 100%;
            margin: 0;
        }
        
        .station-card.reported label {
            cursor: not-allowed;
        }
        
        .station-radio {
            display: none;
        }
        
        /* Sisanya tetap sama */
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
        
        /* Jadwal Indicator */
        .jadwal-indicator {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .indicator-sekali { background: #6f42c1; color: white; }
        .indicator-harian { background: #fd7e14; color: white; }
        .indicator-mingguan { background: #20c997; color: white; }
        .indicator-bulanan { background: #0d6efd; color: white; }
        .indicator-tahunan { background: #dc3545; color: white; }
        
        .priority-indicator {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 8px;
        }
        
        .priority-rendah { background: #6c757d; color: white; }
        .priority-sedang { background: #ffc107; color: #000; }
        .priority-tinggi { background: #fd7e14; color: white; }
        .priority-darurat { background: #dc3545; color: white; }
        
        /* Progress Bar */
        .visit-progress {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .progress-text {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .station-progress {
            color: #0d6efd;
            font-weight: 600;
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
        
        /* Jadwal Cards */
        .jadwal-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
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
            position: absolute;
            top: 15px;
            left: 15px;
            width: 20px;
            height: 20px;
            margin: 0;
            cursor: pointer;
            z-index: 2;
        }
        
        .jadwal-info {
            margin-left: 30px;
        }
        
        .jadwal-info h6 {
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .jadwal-detail {
            font-size: 0.9rem;
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
        
        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Station check icon */
        .station-check-icon {
            position: absolute;
            top: 10px;
            right: 10px;
            color: #198754;
            font-size: 1.2rem;
            display: none;
        }
        
        .station-card.selected .station-check-icon {
            display: block;
        }
        
        /* Image preview */
        .image-preview img {
            max-width: 100%;
            max-height: 150px;
            border-radius: 5px;
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
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Form Laporan -->
        <div class="form-container">
            <form method="POST" action="" enctype="multipart/form-data" id="reportForm">
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
                                $customer_name = !empty($jadwal['nama_perusahaan']) ? $jadwal['nama_perusahaan'] : $jadwal['nama_customer'];
                                $reported_stations = $jadwal['reported_stations_array'] ?? [];
                                $station_count = $jadwal['jumlah_station'] ?? 0;
                                
                                // Tentukan apakah jadwal ini sudah selesai
                                $is_completed = false;
                                if ($jadwal['jenis_periode'] == 'Sekali' && $station_count > 0) {
                                    $is_completed = count($reported_stations) >= $station_count;
                                } elseif ($jadwal['jenis_periode'] == 'Sekali') {
                                    $is_completed = $jadwal['total_laporan_dibuat'] > 0;
                                } else {
                                    $is_completed = $jadwal['kunjungan_berjalan'] >= $jadwal['jumlah_kunjungan'];
                                }
                                
                                // Skip jika sudah selesai
                                if ($is_completed) continue;
                                
                                $next_kunjungan = ($jadwal['last_reported_kunjungan'] ?? 0) + 1;
                                
                                // Tentukan badge warna berdasarkan jenis periode
                                $period_class = 'indicator-' . strtolower($jadwal['jenis_periode']);
                                $priority_class = 'priority-' . strtolower($jadwal['prioritas']);
                            ?>
                                <div class="jadwal-card" id="jadwal-<?php echo $jadwal['jadwal_id']; ?>">
                                    <input type="radio" name="jadwal_id" value="<?php echo $jadwal['jadwal_id']; ?>" 
                                           class="jadwal-radio" 
                                           data-customer-id="<?php echo $jadwal['customer_id']; ?>"
                                           data-service-id="<?php echo $jadwal['service_id']; ?>"
                                           data-station-count="<?php echo $station_count; ?>"
                                           data-jenis-periode="<?php echo $jadwal['jenis_periode']; ?>"
                                           data-jadwal-id="<?php echo $jadwal['jadwal_id']; ?>"
                                           id="jadwal_<?php echo $jadwal['jadwal_id']; ?>">
                                    <div class="jadwal-info">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($customer_name); ?></h6>
                                            <div>
                                                <span class="<?php echo $priority_class; ?> priority-indicator">
                                                    <?php echo $jadwal['prioritas']; ?>
                                                </span>
                                                <span class="<?php echo $period_class; ?> jadwal-indicator">
                                                    <?php echo $jadwal['jenis_periode']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="jadwal-detail">
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($jadwal['nama_service']); ?> (<?php echo $jadwal['kode_service']; ?>)</span>
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
                                                <?php 
                                                $alamat_parts = [];
                                                if (!empty($jadwal['alamat'])) $alamat_parts[] = $jadwal['alamat'];
                                                if (!empty($jadwal['gedung'])) $alamat_parts[] = $jadwal['gedung'];
                                                if (!empty($jadwal['lantai'])) $alamat_parts[] = 'Lt. ' . $jadwal['lantai'];
                                                if (!empty($jadwal['unit'])) $alamat_parts[] = $jadwal['unit'];
                                                echo htmlspecialchars(implode(', ', $alamat_parts) ?: 'Tidak ada alamat'); 
                                                ?>
                                            </small>
                                        </div>
                                        
                                        <!-- Info Station Inspeksi -->
                                        <?php if ($station_count > 0 && $jadwal['jenis_periode'] == 'Sekali'): ?>
                                            <div class="station-info mt-2">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <i class="fas fa-map-pin me-1"></i>
                                                        <strong><?php echo $station_count; ?> Station Inspeksi</strong>
                                                        <span class="badge bg-info ms-2"><?php echo count($reported_stations); ?>/<?php echo $station_count; ?> sudah dilaporkan</span>
                                                    </div>
                                                </div>
                                                
                                                <!-- Station Selector - DISEMBUNYIKAN DI AWAL -->
                                                <div class="station-selector mt-2" id="station-selector-<?php echo $jadwal['jadwal_id']; ?>" style="display: none;">
                                                    <label class="form-label small mb-2">Pilih Station yang Dilaporkan:</label>
                                                    <div class="d-flex flex-wrap gap-2 mb-2" id="station-container-<?php echo $jadwal['jadwal_id']; ?>">
                                                        <?php 
                                                        // Tampilkan station dari data yang sudah diambil
                                                        if (isset($jadwal['stations']) && !empty($jadwal['stations'])) {
                                                            foreach ($jadwal['stations'] as $station): 
                                                                $is_reported = in_array($station['station_number'], $reported_stations);
                                                        ?>
                                                            <div class="station-card <?php echo $is_reported ? 'reported' : ''; ?>" 
                                                                 style="flex: 1 1 calc(50% - 10px); min-width: 200px;"
                                                                 onclick="selectStation(<?php echo $jadwal['jadwal_id']; ?>, <?php echo $station['station_number']; ?>)">
                                                                <input type="radio" 
                                                                       class="station-radio" 
                                                                       name="station_id_<?php echo $jadwal['jadwal_id']; ?>" 
                                                                       value="<?php echo $station['station_number']; ?>"
                                                                       data-jadwal-id="<?php echo $jadwal['jadwal_id']; ?>"
                                                                       id="station_<?php echo $jadwal['jadwal_id']; ?>_<?php echo $station['station_number']; ?>"
                                                                       <?php echo $is_reported ? 'disabled' : ''; ?>>
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <span>
                                                                        <strong>Station #<?php echo $station['station_number']; ?></strong>
                                                                        <br>
                                                                        <small><?php echo htmlspecialchars($station['nama_station']); ?></small>
                                                                    </span>
                                                                    <i class="fas fa-check-circle station-check-icon"></i>
                                                                </div>
                                                                <?php if (!empty($station['lokasi'])): ?>
                                                                    <small class="text-muted d-block mt-1">
                                                                        <i class="fas fa-location-dot me-1"></i>
                                                                        <?php echo htmlspecialchars($station['lokasi']); ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                                <?php if ($is_reported): ?>
                                                                    <small class="text-success d-block mt-1">
                                                                        <i class="fas fa-check me-1"></i>Sudah dilaporkan
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php 
                                                            endforeach;
                                                        } else {
                                                            // Fallback: tampilkan station secara numerik
                                                            for ($i = 1; $i <= $station_count; $i++): 
                                                                $is_reported = in_array($i, $reported_stations);
                                                        ?>
                                                            <div class="station-card <?php echo $is_reported ? 'reported' : ''; ?>" 
                                                                 style="flex: 1 1 calc(50% - 10px); min-width: 200px;"
                                                                 onclick="selectStation(<?php echo $jadwal['jadwal_id']; ?>, <?php echo $i; ?>)">
                                                                <input type="radio" 
                                                                       class="station-radio" 
                                                                       name="station_id_<?php echo $jadwal['jadwal_id']; ?>" 
                                                                       value="<?php echo $i; ?>"
                                                                       data-jadwal-id="<?php echo $jadwal['jadwal_id']; ?>"
                                                                       id="station_<?php echo $jadwal['jadwal_id']; ?>_<?php echo $i; ?>"
                                                                       <?php echo $is_reported ? 'disabled' : ''; ?>>
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <span>
                                                                        <strong>Station #<?php echo $i; ?></strong>
                                                                    </span>
                                                                    <i class="fas fa-check-circle station-check-icon"></i>
                                                                </div>
                                                                <?php if ($is_reported): ?>
                                                                    <small class="text-success d-block mt-1">
                                                                        <i class="fas fa-check me-1"></i>Sudah dilaporkan
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php 
                                                            endfor;
                                                        }
                                                        ?>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small">Nama Station (Opsional):</label>
                                                        <input type="text" 
                                                               name="station_nama_<?php echo $jadwal['jadwal_id']; ?>" 
                                                               class="form-control form-control-sm station-nama-input" 
                                                               data-jadwal-id="<?php echo $jadwal['jadwal_id']; ?>"
                                                               placeholder="Contoh: Area Parkir Bawah, Ruang Server, dll">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($jadwal['jenis_periode'] != 'Sekali'): ?>
                                            <div class="visit-progress">
                                                <span class="progress-text">
                                                    Kunjungan ke-<?php echo $next_kunjungan; ?> dari <?php echo $jadwal['jumlah_kunjungan']; ?>
                                                </span>
                                                <?php if ($jadwal['total_laporan_dibuat'] > 0): ?>
                                                    <span class="ms-2">
                                                        <i class="fas fa-check-circle text-success me-1"></i>
                                                        <?php echo $jadwal['total_laporan_dibuat']; ?> laporan sudah dibuat
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($jadwal['catatan_admin']): ?>
                                            <div class="jadwal-detail mt-2 p-2 bg-light rounded">
                                                <small>
                                                    <i class="fas fa-sticky-note me-1 text-warning"></i>
                                                    <strong>Catatan Admin:</strong> <?php echo htmlspecialchars($jadwal['catatan_admin']); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Manual Input -->
                    <div id="manual-section" style="display: none;">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pilih Customer <span class="text-danger">*</span></label>
                                <select name="customer_id" class="form-select" id="customer_select" required>
                                    <option value="">-- Pilih Customer --</option>
                                    <?php foreach ($customer_data as $customer): 
                                        $customer_display = !empty($customer['nama_customer']) 
                                            ? $customer['nama_customer'] . ' (' . $customer['nama_perusahaan'] . ')'
                                            : $customer['nama_perusahaan'];
                                        $station_info = ($customer['jumlah_station'] > 0) ? " ({$customer['jumlah_station']} station)" : "";
                                    ?>
                                        <option value="<?php echo $customer['id']; ?>" data-station-count="<?php echo $customer['jumlah_station']; ?>">
                                            <?php echo htmlspecialchars($customer_display . $station_info); ?> 
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="text-muted small mt-1">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Pilih customer untuk melihat layanan yang tersedia
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pilih Layanan <span class="text-danger">*</span></label>
                                <select name="service_id" class="form-select" id="service_select" required disabled>
                                    <option value="">-- Pilih Customer terlebih dahulu --</option>
                                </select>
                                <div class="text-muted small mt-1" id="service-info">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Layanan akan muncul setelah memilih customer
                                </div>
                                <div id="service-loading" style="display: none;">
                                    <div class="loading-spinner"></div>
                                    <span class="ms-2">Memuat layanan...</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Station untuk manual input -->
                        <div id="manual-station-section" style="display: none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nomor Station (Opsional)</label>
                                    <input type="number" name="station_id" class="form-control" 
                                           min="1" placeholder="Contoh: 1, 2, 3, ...">
                                    <small class="text-muted">Jika melaporkan untuk station tertentu</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nama Station (Opsional)</label>
                                    <input type="text" name="station_nama" class="form-control" 
                                           placeholder="Contoh: Area Parkir Bawah, Ruang Server, dll">
                                    <small class="text-muted">Nama atau lokasi spesifik station</small>
                                </div>
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
                            <div class="d-flex align-items-center gap-2">
                                <input type="time" name="jam_mulai" class="form-control" 
                                       value="<?php echo $_POST['jam_mulai'] ?? date('H:i'); ?>" required>
                                <span class="fw-bold">s/d</span>
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
                                <input type="file" name="foto_bukti" accept="image/*" id="fotoBukti">
                                <div class="image-preview mt-2" id="previewBukti"></div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="file-upload">
                                <i class="fas fa-image"></i>
                                <h6 class="mb-2">Foto Sebelum</h6>
                                <p class="text-muted small">Foto kondisi sebelum (opsional)</p>
                                <input type="file" name="foto_sebelum" accept="image/*" id="fotoSebelum">
                                <div class="image-preview mt-2" id="previewSebelum"></div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="file-upload">
                                <i class="fas fa-images"></i>
                                <h6 class="mb-2">Foto Sesudah</h6>
                                <p class="text-muted small">Foto kondisi sesudah (opsional)</p>
                                <input type="file" name="foto_sesudah" accept="image/*" id="fotoSesudah">
                                <div class="image-preview mt-2" id="previewSesudah"></div>
                            </div>
                        </div>
                    </div>
                    
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Format: JPG, PNG, GIF, WEBP (Maksimal 5MB per file)
                    </small>
                </div>
                
                <!-- Hidden fields untuk station -->
                <input type="hidden" name="station_id" id="station_id_field" value="">
                <input type="hidden" name="station_nama" id="station_nama_field" value="">
                
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
    // Fungsi untuk memilih station
    function selectStation(jadwalId, stationId) {
        console.log('selectStation called:', { jadwalId, stationId });
        
        // Reset semua station card untuk jadwal ini
        const stationContainer = document.getElementById(`station-container-${jadwalId}`);
        if (stationContainer) {
            stationContainer.querySelectorAll('.station-card').forEach(card => {
                if (!card.classList.contains('reported')) {
                    card.classList.remove('selected');
                }
            });
        }
        
        // Aktifkan station yang dipilih
        const stationRadio = document.getElementById(`station_${jadwalId}_${stationId}`);
        if (stationRadio && !stationRadio.disabled) {
            stationRadio.checked = true;
            const selectedCard = stationRadio.closest('.station-card');
            if (selectedCard) {
                selectedCard.classList.add('selected');
                
                // Set hidden fields
                document.getElementById('station_id_field').value = stationId;
                
                // Ambil nama station jika ada
                const stationNamaInput = document.querySelector(`.station-nama-input[data-jadwal-id="${jadwalId}"]`);
                if (stationNamaInput) {
                    document.getElementById('station_nama_field').value = stationNamaInput.value;
                }
                
                console.log('Station selected:', {
                    station_id: stationId,
                    station_nama: document.getElementById('station_nama_field').value
                });
            }
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle antara jadwal dan manual
        const scheduleRadio = document.getElementById('source_schedule');
        const manualRadio = document.getElementById('source_manual');
        const scheduleSection = document.getElementById('schedule-section');
        const manualSection = document.getElementById('manual-section');
        const manualStationSection = document.getElementById('manual-station-section');
        const customerSelect = document.getElementById('customer_select');
        const serviceSelect = document.getElementById('service_select');
        
        function toggleSections() {
            if (scheduleRadio.checked) {
                scheduleSection.style.display = 'block';
                manualSection.style.display = 'none';
                // Reset hidden fields
                document.getElementById('station_id_field').value = '';
                document.getElementById('station_nama_field').value = '';
                
                // Disable manual inputs
                customerSelect.disabled = true;
                customerSelect.required = false;
                serviceSelect.disabled = true;
                serviceSelect.required = false;
                // Enable schedule inputs
                document.querySelectorAll('#schedule-section input[type="radio"]').forEach(el => {
                    el.disabled = false;
                    el.required = true;
                });
            } else {
                scheduleSection.style.display = 'none';
                manualSection.style.display = 'block';
                // Disable schedule inputs
                document.querySelectorAll('#schedule-section input[type="radio"]').forEach(el => {
                    el.disabled = true;
                    el.required = false;
                });
                // Enable customer input, but service still disabled until customer selected
                customerSelect.disabled = false;
                customerSelect.required = true;
                serviceSelect.disabled = true;
                serviceSelect.required = false;
                
                // Tampilkan station section jika customer punya station
                toggleManualStationSection();
            }
        }
        
        scheduleRadio.addEventListener('change', toggleSections);
        manualRadio.addEventListener('change', toggleSections);
        
        // Initialize
        toggleSections();
        
        // Jadwal card selection - Klik pada seluruh card
        document.querySelectorAll('.jadwal-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Jangan trigger jika yang diklik adalah radio button
                if (e.target.type === 'radio') return;
                
                const radio = this.querySelector('.jadwal-radio');
                if (radio && !radio.disabled) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change'));
                }
            });
        });
        
        // Jadwal radio button change event
        document.querySelectorAll('.jadwal-radio').forEach(radio => {
            radio.addEventListener('change', function() {
                console.log('Jadwal radio changed:', this.value);
                
                // Reset semua jadwal card
                document.querySelectorAll('.jadwal-card').forEach(card => {
                    card.classList.remove('selected');
                });
                
                // SEMBUNYIKAN semua station selector
                document.querySelectorAll('.station-selector').forEach(selector => {
                    selector.style.display = 'none';
                });
                
                // Reset semua station selection
                document.querySelectorAll('.station-card').forEach(card => {
                    if (!card.classList.contains('reported')) {
                        card.classList.remove('selected');
                    }
                });
                
                // Reset hidden fields
                document.getElementById('station_id_field').value = '';
                document.getElementById('station_nama_field').value = '';
                
                if (this.checked) {
                    const jadwalCard = this.closest('.jadwal-card');
                    if (jadwalCard) {
                        jadwalCard.classList.add('selected');
                    }
                    
                    // Tampilkan station selector jika ada station
                    const stationCount = parseInt(this.dataset.stationCount || 0);
                    const jenisPeriode = this.dataset.jenisPeriode;
                    const jadwalId = this.dataset.jadwalId || this.value;
                    
                    console.log('Jadwal selected:', { 
                        stationCount, 
                        jenisPeriode, 
                        jadwalId,
                        hasStationSelector: document.getElementById(`station-selector-${jadwalId}`) !== null
                    });
                    
                    if (jenisPeriode === 'Sekali' && stationCount > 0) {
                        const stationSelector = document.getElementById(`station-selector-${jadwalId}`);
                        
                        if (stationSelector) {
                            stationSelector.style.display = 'block';
                            console.log('Station selector displayed for jadwal:', jadwalId);
                            
                            // Auto-select first available station jika belum ada yang dipilih
                            setTimeout(() => {
                                const availableStation = stationSelector.querySelector('.station-card:not(.reported)');
                                if (availableStation && !document.getElementById('station_id_field').value) {
                                    const stationId = availableStation.querySelector('.station-radio').value;
                                    selectStation(jadwalId, stationId);
                                    console.log('Auto-selected station:', stationId);
                                }
                            }, 100);
                        } else {
                            console.error('Station selector not found for jadwal:', jadwalId);
                        }
                    } else {
                        console.log('No station selector needed for this jadwal');
                    }
                }
            });
        });
        
        // Update station nama ketika diinput
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('station-nama-input')) {
                const jadwalId = e.target.dataset.jadwalId;
                const selectedStation = document.querySelector(`input[name="station_id_${jadwalId}"]:checked`);
                if (selectedStation) {
                    document.getElementById('station_nama_field').value = e.target.value;
                    console.log('Station name updated:', e.target.value);
                }
            }
        });
        
        // Load services based on selected customer (manual mode)
        customerSelect.addEventListener('change', function() {
            const customerId = this.value;
            const selectedOption = this.options[this.selectedIndex];
            const stationCount = parseInt(selectedOption.dataset.stationCount || 0);
            
            toggleManualStationSection(stationCount);
            
            const serviceSelect = document.getElementById('service_select');
            const serviceInfo = document.getElementById('service-info');
            const serviceLoading = document.getElementById('service-loading');
            
            if (!customerId) {
                serviceSelect.innerHTML = '<option value="">-- Pilih Customer terlebih dahulu --</option>';
                serviceSelect.disabled = true;
                serviceInfo.style.display = 'block';
                serviceLoading.style.display = 'none';
                return;
            }
            
            // Show loading
            serviceSelect.disabled = true;
            serviceInfo.style.display = 'none';
            serviceLoading.style.display = 'block';
            
            // Clear current options
            serviceSelect.innerHTML = '<option value="">-- Memuat layanan... --</option>';
            
            // Fetch services via AJAX
            fetch(`create_report.php?action=get_services&customer_id=${customerId}`)
                .then(response => response.json())
                .then(data => {
                    serviceLoading.style.display = 'none';
                    
                    if (data.error) {
                        serviceSelect.innerHTML = `<option value="">${data.error}</option>`;
                        serviceInfo.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i> ${data.error}`;
                        serviceInfo.style.display = 'block';
                        return;
                    }
                    
                    if (data.length === 0) {
                        serviceSelect.innerHTML = '<option value="">-- Customer tidak memiliki layanan aktif --</option>';
                        serviceSelect.disabled = true;
                        serviceInfo.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Customer ini tidak memiliki layanan aktif. Pilih customer lain atau hubungi admin.';
                        serviceInfo.style.display = 'block';
                    } else {
                        serviceSelect.innerHTML = '<option value="">-- Pilih Layanan --</option>';
                        data.forEach(service => {
                            const option = document.createElement('option');
                            option.value = service.id;
                            option.textContent = `${service.nama_service} (${service.kode_service})`;
                            serviceSelect.appendChild(option);
                        });
                        serviceSelect.disabled = false;
                        serviceInfo.innerHTML = `<i class="fas fa-check-circle me-1 text-success"></i> ${data.length} layanan tersedia`;
                        serviceInfo.style.display = 'block';
                    }
                })
                .catch(error => {
                    serviceLoading.style.display = 'none';
                    serviceSelect.innerHTML = '<option value="">-- Gagal memuat layanan --</option>';
                    serviceInfo.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i> Gagal memuat data layanan. Silakan coba lagi.`;
                    serviceInfo.style.display = 'block';
                    console.error('Error loading services:', error);
                });
        });
        
        // Tampilkan/sembunyikan station section untuk manual mode
        function toggleManualStationSection(stationCount = 0) {
            if (stationCount > 0) {
                manualStationSection.style.display = 'block';
                // Update placeholder untuk station id
                const stationIdInput = document.querySelector('input[name="station_id"]');
                if (stationIdInput) {
                    stationIdInput.max = stationCount;
                    stationIdInput.placeholder = `1 sampai ${stationCount}`;
                }
            } else {
                manualStationSection.style.display = 'none';
            }
        }
        
        // Image preview function
        function setupImagePreview(inputId, previewId) {
            const fileInput = document.getElementById(inputId);
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
                        
                        reader.onload = function(e) {
                            previewContainer.innerHTML = `
                                <div class="card">
                                    <img src="${e.target.result}" class="card-img-top" alt="Preview" style="max-height: 150px; object-fit: cover;">
                                    <div class="card-body p-2 text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-preview">
                                            <i class="fas fa-trash me-1"></i>Hapus
                                        </button>
                                    </div>
                                </div>
                            `;
                            
                            // Add remove functionality
                            previewContainer.querySelector('.remove-preview').addEventListener('click', function() {
                                fileInput.value = '';
                                previewContainer.innerHTML = '';
                            });
                        };
                        
                        reader.readAsDataURL(file);
                    } else {
                        previewContainer.innerHTML = '';
                    }
                });
            }
        }
        
        // Setup image previews
        setupImagePreview('fotoBukti', 'previewBukti');
        setupImagePreview('fotoSebelum', 'previewSebelum');
        setupImagePreview('fotoSesudah', 'previewSesudah');
        
        // Form validation
        const form = document.getElementById('reportForm');
        form.addEventListener('submit', function(e) {
            let isValid = true;
            let errorMessage = '';
            
            // Check if manual mode is selected
            if (manualRadio.checked) {
                if (!customerSelect.value) {
                    errorMessage = 'Silakan pilih customer terlebih dahulu!';
                    customerSelect.focus();
                    isValid = false;
                } else if (!serviceSelect.value || serviceSelect.disabled) {
                    errorMessage = 'Silakan pilih layanan!';
                    serviceSelect.focus();
                    isValid = false;
                }
            } else {
                // Check if schedule mode is selected
                const selectedJadwal = document.querySelector('input[name="jadwal_id"]:checked');
                if (!selectedJadwal) {
                    errorMessage = 'Silakan pilih jadwal yang akan dilaporkan!';
                    isValid = false;
                } else {
                    // Check if station perlu dipilih
                    const stationCount = parseInt(selectedJadwal.dataset.stationCount || 0);
                    const jenisPeriode = selectedJadwal.dataset.jenisPeriode;
                    
                    if (jenisPeriode === 'Sekali' && stationCount > 0) {
                        const stationSelected = document.getElementById('station_id_field').value;
                        if (!stationSelected) {
                            errorMessage = 'Silakan pilih station yang akan dilaporkan!';
                            isValid = false;
                        }
                    }
                }
            }
            
            // Check time validation
            const jamMulai = document.querySelector('input[name="jam_mulai"]').value;
            const jamSelesai = document.querySelector('input[name="jam_selesai"]').value;
            
            if (jamMulai && jamSelesai) {
                const start = new Date('2000-01-01T' + jamMulai + ':00');
                const end = new Date('2000-01-01T' + jamSelesai + ':00');
                
                if (end <= start) {
                    errorMessage = 'Jam selesai harus setelah jam mulai!';
                    isValid = false;
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                alert(errorMessage);
                return false;
            }
            
            // Set hidden fields for manual mode
            if (manualRadio.checked) {
                const stationIdInput = document.querySelector('input[name="station_id"]');
                const stationNamaInput = document.querySelector('input[name="station_nama"]');
                
                if (stationIdInput && stationIdInput.value) {
                    document.getElementById('station_id_field').value = stationIdInput.value;
                }
                if (stationNamaInput && stationNamaInput.value) {
                    document.getElementById('station_nama_field').value = stationNamaInput.value;
                }
            }
            
            // Konfirmasi sebelum submit
            const confirmation = confirm('Apakah Anda yakin ingin menyimpan laporan ini?');
            if (!confirmation) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Auto-select first available jadwal if only one
        const jadwalRadios = document.querySelectorAll('.jadwal-radio');
        if (jadwalRadios.length === 1) {
            jadwalRadios[0].checked = true;
            // Trigger change event untuk menampilkan station selector jika ada
            setTimeout(() => {
                jadwalRadios[0].dispatchEvent(new Event('change'));
            }, 100);
        }
        
        // Jika ada jadwal yang sudah terpilih dari reload form, trigger change
        const selectedJadwal = document.querySelector('input[name="jadwal_id"]:checked');
        if (selectedJadwal) {
            setTimeout(() => {
                selectedJadwal.dispatchEvent(new Event('change'));
            }, 200);
        }
    });
    </script>
</body>
</html>