<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Fungsi untuk mengecek apakah jadwal bisa diedit
function canEditSchedule($schedule_id, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM jadwal WHERE id = ?");
        $stmt->execute([$schedule_id]);
        $status = $stmt->fetchColumn();
        
        // Jadwal hanya bisa diedit jika status bukan 'Berjalan'
        return $status !== 'Berjalan';
    } catch (PDOException $e) {
        error_log("canEditSchedule Error: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk mengecek apakah jadwal bisa dihapus
function canDeleteSchedule($schedule_id, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT status, (SELECT COUNT(*) FROM reports WHERE jadwal_id = ?) as report_count FROM jadwal WHERE id = ?");
        $stmt->execute([$schedule_id, $schedule_id]);
        $data = $stmt->fetch();
        
        // Jadwal bisa dihapus jika status bukan 'Berjalan' dan tidak ada laporan
        return ($data['status'] !== 'Berjalan' && $data['report_count'] == 0);
    } catch (PDOException $e) {
        error_log("canDeleteSchedule Error: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk mengecek apakah pekerja memiliki jadwal bentrok
function isWorkerAvailable($worker_id, $tanggal, $jam, $durasi_menit, $pdo, $exclude_schedule_id = null) {
    try {
        // Jika pekerja_id null atau kosong, skip validasi
        if (empty($worker_id)) {
            return ['available' => true, 'message' => ''];
        }
        
        // Konversi waktu ke format database
        $start_time = $jam;
        $start_datetime = $tanggal . ' ' . $start_time;
        
        // Hitung waktu selesai berdasarkan durasi layanan
        if (!empty($durasi_menit)) {
            $end_datetime = date('Y-m-d H:i:s', strtotime("$start_datetime + $durasi_menit minutes"));
            $end_time = date('H:i:s', strtotime($end_datetime));
        } else {
            // Default durasi 60 menit jika tidak ditentukan
            $end_datetime = date('Y-m-d H:i:s', strtotime("$start_datetime + 60 minutes"));
            $end_time = date('H:i:s', strtotime($end_datetime));
            $durasi_menit = 60;
        }
        
        // Query untuk cek bentrok dengan semua customer
        $query = "
            SELECT 
                j.id, 
                j.kode_jadwal, 
                j.tanggal, 
                j.jam, 
                j.durasi_estimasi,
                TIME_TO_SEC(TIMEDIFF(
                    ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '01:00:00')),
                    ?
                )) as waktu_sebelum,
                TIME_TO_SEC(TIMEDIFF(
                    ?,
                    j.jam
                )) as waktu_setelah,
                c.nama_perusahaan,
                s.nama_service,
                s.durasi_menit as durasi_layanan
            FROM jadwal j
            LEFT JOIN customers c ON j.customer_id = c.id
            LEFT JOIN services s ON j.service_id = s.id
            WHERE j.pekerja_id = ?
            AND j.status NOT IN ('Selesai', 'Dibatalkan')
            AND j.tanggal = ?
            AND (
                -- Kasus 1: Jadwal baru dimulai DURING jadwal yang ada
                (? >= j.jam AND ? < ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '01:00:00')))
                OR
                -- Kasus 2: Jadwal baru berakhir DURING jadwal yang ada
                (? > j.jam AND ? <= ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '01:00:00')))
                OR
                -- Kasus 3: Jadwal yang ada dimulai DURING jadwal baru
                (j.jam >= ? AND j.jam < ?)
                OR
                -- Kasus 4: Jadwal yang ada berakhir DURING jadwal baru
                (ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '01:00:00')) > ? 
                 AND ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '01:00:00')) <= ?)
            )
        ";
        
        $params = [
            $start_time,
            $end_time,
            $worker_id,
            $tanggal,
            $start_time, $start_time,
            $end_time, $end_time,
            $start_time, $end_time,
            $start_time, $end_time
        ];
        
        // Jika sedang edit, exclude jadwal yang sedang diedit
        if ($exclude_schedule_id) {
            $query .= " AND j.id != ?";
            $params[] = $exclude_schedule_id;
        }
        
        $query .= " ORDER BY j.jam ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($conflicts)) {
            return ['available' => true, 'message' => ''];
        } else {
            $conflict_info = [];
            foreach ($conflicts as $conflict) {
                // Hitung waktu selesai dari jadwal yang bentrok
                $conflict_duration = !empty($conflict['durasi_estimasi']) ? $conflict['durasi_estimasi'] : 60;
                $conflict_end_time = date('H:i', strtotime($conflict['jam'] . ' + ' . $conflict_duration . ' minutes'));
                
                $conflict_info[] = sprintf(
                    "• %s: %s %s-%s (%s - %s)",
                    $conflict['kode_jadwal'],
                    $conflict['tanggal'],
                    $conflict['jam'],
                    $conflict_end_time,
                    $conflict['nama_perusahaan'],
                    $conflict['nama_service']
                );
            }
            
            $message = "⚠️ Pekerja sudah memiliki jadwal pada waktu yang sama:\n\n" . 
                       implode("\n", $conflict_info) .
                       "\n\nDurasi layanan yang dipilih: " . $durasi_menit . " menit";
            
            return ['available' => false, 'message' => $message, 'conflicts' => $conflicts];
        }
        
    } catch (PDOException $e) {
        error_log("isWorkerAvailable Error: " . $e->getMessage());
        return ['available' => false, 'message' => 'Error checking worker availability: ' . $e->getMessage()];
    }
}

// Inisialisasi filter
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_customer = $_GET['customer'] ?? '';
$filter_worker = $_GET['worker'] ?? '';
$filter_periode = $_GET['periode'] ?? '';

// Ambil data untuk dropdowns
try {
    // AMBIL CUSTOMERS DENGAN JUMLAH_STATION DAN LAYANAN - DIPERBAIKI
    $customers = $pdo->query("
        SELECT 
            c.id, 
            CONCAT(c.nama_perusahaan, ' - ', c.nama_customer) as display_name, 
            c.nama_perusahaan, 
            c.nama_customer, 
            c.telepon, 
            c.alamat,
            c.gedung, 
            c.lantai, 
            c.unit, 
            c.jumlah_station,
            -- Tambah kolom untuk layanan
            GROUP_CONCAT(DISTINCT CONCAT(s.kode_service, ' - ', s.nama_service) SEPARATOR '; ') as services_list,
            GROUP_CONCAT(DISTINCT s.id) as service_ids,
            COUNT(DISTINCT cs.id) as total_services
        FROM customers c
        LEFT JOIN customer_services cs ON c.id = cs.customer_id AND cs.status = 'Aktif'
        LEFT JOIN services s ON cs.service_id = s.id
        WHERE c.status = 'Aktif' 
        GROUP BY c.id
        ORDER BY c.nama_perusahaan ASC
    ")->fetchAll();
    
    // Ambil semua layanan aktif (untuk referensi)
    $services = $pdo->query("
        SELECT s.id, s.kode_service, s.nama_service, s.harga, s.durasi_menit
        FROM services s
        WHERE s.status = 'Aktif'
        ORDER BY s.nama_service ASC
    ")->fetchAll();
    
    $workers = $pdo->query("
        SELECT u.id, u.nama, u.jabatan 
        FROM users u
        WHERE u.status = 'Aktif' 
        ORDER BY u.nama ASC
    ")->fetchAll();
    
    // Untuk filter dropdowns
    $all_customers_for_filter = $pdo->query("
        SELECT id, CONCAT(nama_perusahaan, ' - ', nama_customer) as display_name 
        FROM customers 
        ORDER BY nama_perusahaan ASC
    ")->fetchAll();
    
    $all_workers_for_filter = $pdo->query("
        SELECT id, nama 
        FROM users 
        ORDER BY nama ASC
    ")->fetchAll();
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data: " . $e->getMessage();
    $customers = $services = $workers = [];
    $all_customers_for_filter = $all_workers_for_filter = [];
}

// =========================
// HANDLING FORM CREATE/UPDATE
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    // UPDATE EXISTING SCHEDULE - Validasi status
    if ($action === 'update' && $id) {
        // Cek status jadwal sebelum update
        try {
            $stmt = $pdo->prepare("SELECT status FROM jadwal WHERE id = ?");
            $stmt->execute([$id]);
            $current_status = $stmt->fetchColumn();
            
            // Jika status Berjalan, cegah update
            if ($current_status === 'Berjalan') {
                $_SESSION['error'] = 'Jadwal dengan status "Berjalan" tidak dapat diedit.';
                header("Location: schedule.php");
                exit();
            }
            
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Gagal memvalidasi jadwal: ' . $e->getMessage();
            header("Location: schedule.php");
            exit();
        }
    }
    
    // Validasi required fields
    if (empty($_POST['customer_id']) || empty($_POST['tanggal']) || empty($_POST['jam'])) {
        $_SESSION['error'] = 'Customer, Tanggal, dan Jam harus diisi!';
    } else {
        $customer_id = (int)$_POST['customer_id'];
        $pekerja_id = !empty($_POST['pekerja_id']) ? (int)$_POST['pekerja_id'] : null;
        $tanggal = sanitizeInput($_POST['tanggal']);
        $jam = sanitizeInput($_POST['jam']);
        $lokasi = sanitizeInput($_POST['lokasi'] ?? '');
        $catatan_admin = sanitizeInput($_POST['catatan_admin'] ?? '');
        $prioritas = sanitizeInput($_POST['prioritas'] ?? 'Sedang');
        $durasi_estimasi = !empty($_POST['durasi_estimasi']) ? (int)$_POST['durasi_estimasi'] : null;
        $jenis_periode = sanitizeInput($_POST['jenis_periode'] ?? 'Sekali');
        $jumlah_kunjungan = !empty($_POST['jumlah_kunjungan']) ? (int)$_POST['jumlah_kunjungan'] : 1;
        $google_maps_url = sanitizeInput($_POST['google_maps_url'] ?? '');
        
        // VALIDASI: Cek ketersediaan pekerja
        if (!empty($pekerja_id)) {
            $availability = isWorkerAvailable($pekerja_id, $tanggal, $jam, $durasi_estimasi, $pdo, $id);
            
            if (!$availability['available']) {
                $_SESSION['error'] = $availability['message'];
                header("Location: schedule.php");
                exit();
            }
        }
        
        // Ambil jumlah_station dan layanan dari customer
        try {
            $stmt = $pdo->prepare("
                SELECT c.jumlah_station, 
                       GROUP_CONCAT(cs.service_id) as service_ids
                FROM customers c
                LEFT JOIN customer_services cs ON c.id = cs.customer_id AND cs.status = 'Aktif'
                WHERE c.id = ?
                GROUP BY c.id
            ");
            $stmt->execute([$customer_id]);
            $customer_data = $stmt->fetch();
            
            $jumlah_station = $customer_data ? $customer_data['jumlah_station'] : 0;
            $service_ids = $customer_data && $customer_data['service_ids'] ? 
                         explode(',', $customer_data['service_ids']) : [];
            
            // Validasi: customer harus punya minimal 1 layanan
            if (empty($service_ids) || $service_ids[0] === null) {
                $_SESSION['error'] = 'Customer ini belum memiliki layanan aktif!';
                header("Location: schedule.php");
                exit();
            }
            
            // Ambil layanan pertama sebagai default
            $service_id = (int)$service_ids[0];
            
            // Jika jumlah_station ada dan lebih besar dari 0, gunakan untuk kunjungan
            if ($jumlah_station > 0 && $jenis_periode === 'Sekali') {
                $jumlah_kunjungan = $jumlah_station;
            }
            
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Gagal mengambil data customer: ' . $e->getMessage();
            header("Location: schedule.php");
            exit();
        }
        
        // Validasi untuk kunjungan berulang
        if ($jenis_periode !== 'Sekali' && $jumlah_kunjungan < 2) {
            $_SESSION['error'] = 'Jumlah kunjungan minimal 2 untuk jadwal berulang!';
        } elseif ($jenis_periode !== 'Sekali' && $jumlah_kunjungan > 100) {
            $_SESSION['error'] = 'Jumlah kunjungan maksimal 100!';
        } else {
            // Validasi tanggal tidak di masa lalu
            $selected_date = new DateTime($tanggal);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($selected_date < $today) {
                $_SESSION['error'] = 'Tanggal tidak boleh di masa lalu!';
            } else {
                try {
                    $admin_id = $_SESSION['admin_id']; // Admin ID dari session
                    
                    // CREATE NEW SCHEDULE
                    if ($action === 'create') {
                        // Biarkan trigger database generate kode jadwal
                        $stmt = $pdo->prepare("
                            INSERT INTO jadwal (
                                admin_id, pekerja_id, customer_id, service_id, 
                                tanggal, jam, lokasi, google_maps_url, durasi_estimasi, 
                                status, prioritas, catatan_admin, jenis_periode, jumlah_kunjungan, kunjungan_berjalan
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Menunggu', ?, ?, ?, ?, 0)
                        ");
                        $stmt->execute([
                            $admin_id, $pekerja_id, $customer_id, $service_id,
                            $tanggal, $jam, $lokasi, $google_maps_url, $durasi_estimasi, 
                            $prioritas, $catatan_admin, $jenis_periode, $jumlah_kunjungan
                        ]);
                        
                        $jadwal_id = $pdo->lastInsertId();
                        
                        // Ambil kode jadwal yang di-generate oleh trigger
                        $stmt = $pdo->prepare("SELECT kode_jadwal FROM jadwal WHERE id = ?");
                        $stmt->execute([$jadwal_id]);
                        $kode_jadwal = $stmt->fetchColumn();
                        
                        // Buat jadwal berulang jika diperlukan
                        if ($jenis_periode !== 'Sekali' && $jumlah_kunjungan > 1) {
                            $parent_schedule = $pdo->query("SELECT * FROM jadwal WHERE id = $jadwal_id")->fetch();
                            
                            for ($i = 1; $i < $jumlah_kunjungan; $i++) {
                                $next_date = $tanggal;
                                
                                // Hitung tanggal berikutnya
                                switch ($jenis_periode) {
                                    case 'Harian':
                                        $next_date = date('Y-m-d', strtotime("+{$i} day", strtotime($tanggal)));
                                        break;
                                    case 'Mingguan':
                                        $next_date = date('Y-m-d', strtotime("+{$i} week", strtotime($tanggal)));
                                        break;
                                    case 'Bulanan':
                                        $next_date = date('Y-m-d', strtotime("+{$i} month", strtotime($tanggal)));
                                        break;
                                    case 'Tahunan':
                                        $next_date = date('Y-m-d', strtotime("+{$i} year", strtotime($tanggal)));
                                        break;
                                }
                                
                                // VALIDASI: Cek ketersediaan pekerja untuk child schedule
                                if (!empty($pekerja_id)) {
                                    $child_availability = isWorkerAvailable($pekerja_id, $next_date, $jam, $durasi_estimasi, $pdo);
                                    
                                    if (!$child_availability['available']) {
                                        // Jika ada bentrok, jangan assign pekerja ke child schedule
                                        $child_pekerja_id = null;
                                    } else {
                                        $child_pekerja_id = $pekerja_id;
                                    }
                                } else {
                                    $child_pekerja_id = null;
                                }
                                
                                // Insert child schedule (biarkan trigger generate kode)
                                $stmt = $pdo->prepare("
                                    INSERT INTO jadwal (
                                        admin_id, pekerja_id, customer_id, service_id, 
                                        tanggal, jam, lokasi, google_maps_url, durasi_estimasi, 
                                        status, prioritas, catatan_admin, jenis_periode, jumlah_kunjungan, 
                                        kunjungan_berjalan, parent_jadwal_id
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Menunggu', ?, ?, ?, ?, 0, ?)
                                ");
                                $stmt->execute([
                                    $admin_id, $child_pekerja_id, $customer_id, $service_id,
                                    $next_date, $jam, $lokasi, $google_maps_url, $durasi_estimasi, 
                                    $prioritas, $catatan_admin, $jenis_periode, $jumlah_kunjungan, $jadwal_id
                                ]);
                            }
                            
                            $_SESSION['success'] = 'Jadwal berulang berhasil dibuat! ' . $jumlah_kunjungan . ' kunjungan ' . strtolower($jenis_periode) . ' (Kode: ' . $kode_jadwal . ')';
                        } else {
                            $_SESSION['success'] = 'Jadwal berhasil dibuat! Kode: ' . $kode_jadwal . ' (' . $jumlah_kunjungan . ' station)';
                        }
                        
                    } 
                    // UPDATE EXISTING SCHEDULE
                    elseif ($action === 'update' && $id) {
                        // Ambil service_id dari jadwal yang ada untuk mempertahankan konsistensi
                        $stmt = $pdo->prepare("SELECT service_id FROM jadwal WHERE id = ?");
                        $stmt->execute([$id]);
                        $existing_schedule = $stmt->fetch();
                        
                        $service_id = $existing_schedule ? $existing_schedule['service_id'] : $service_id;
                        
                        // Update jadwal utama
                        $stmt = $pdo->prepare("
                            UPDATE jadwal SET 
                                pekerja_id = ?, customer_id = ?, service_id = ?, 
                                tanggal = ?, jam = ?, lokasi = ?, google_maps_url = ?, 
                                durasi_estimasi = ?, prioritas = ?, catatan_admin = ?,
                                jenis_periode = ?, jumlah_kunjungan = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $pekerja_id, $customer_id, $service_id,
                            $tanggal, $jam, $lokasi, $google_maps_url, $durasi_estimasi,
                            $prioritas, $catatan_admin, $jenis_periode, $jumlah_kunjungan, $id
                        ]);
                        
                        // Update child schedules juga (hanya tanggal dan jam)
                        if ($jenis_periode !== 'Sekali') {
                            // Ambil semua child schedules
                            $stmt = $pdo->prepare("
                                SELECT id FROM jadwal 
                                WHERE parent_jadwal_id = ? 
                                ORDER BY tanggal ASC
                            ");
                            $stmt->execute([$id]);
                            $child_schedules = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            // Update setiap child schedule
                            foreach ($child_schedules as $index => $child_id) {
                                $kunjungan_ke = $index + 2; // Mulai dari kunjungan ke-2
                                
                                // Hitung tanggal untuk child schedule
                                $child_date = $tanggal;
                                switch ($jenis_periode) {
                                    case 'Harian':
                                        $child_date = date('Y-m-d', strtotime("+{$kunjungan_ke} day", strtotime($tanggal)));
                                        break;
                                    case 'Mingguan':
                                        $child_date = date('Y-m-d', strtotime("+{$kunjungan_ke} week", strtotime($tanggal)));
                                        break;
                                    case 'Bulanan':
                                        $child_date = date('Y-m-d', strtotime("+{$kunjungan_ke} month", strtotime($tanggal)));
                                        break;
                                    case 'Tahunan':
                                        $child_date = date('Y-m-d', strtotime("+{$kunjungan_ke} year", strtotime($tanggal)));
                                        break;
                                }
                                
                                // Update child schedule
                                $stmt = $pdo->prepare("
                                    UPDATE jadwal SET 
                                        tanggal = ?, jam = ?, pekerja_id = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$child_date, $jam, $pekerja_id, $child_id]);
                            }
                        }
                        
                        $_SESSION['success'] = 'Jadwal berhasil diperbarui!';
                    }
                    
                } catch (PDOException $e) {
                    $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage();
                    error_log("Schedule Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                }
            }
        }
    }
    
    header("Location: schedule.php");
    exit();
}

// =========================
// UPDATE STATUS JADWAL
// =========================
if (isset($_GET['update_status'])) {
    $id = (int)$_GET['id'];
    $status = $_GET['update_status'];
    $allowed_statuses = ['Menunggu', 'Berjalan', 'Selesai', 'Dibatalkan'];
    
    if (in_array($status, $allowed_statuses)) {
        try {
            // Cek apakah jadwal bisa diubah ke status Berjalan (hanya dari Menunggu)
            if ($status === 'Berjalan') {
                $stmt = $pdo->prepare("SELECT status FROM jadwal WHERE id = ?");
                $stmt->execute([$id]);
                $current_status = $stmt->fetchColumn();
                
                if ($current_status !== 'Menunggu') {
                    $_SESSION['error'] = 'Jadwal hanya bisa dimulai dari status "Menunggu"!';
                    header("Location: schedule.php");
                    exit();
                }
            }
            
            $stmt = $pdo->prepare("UPDATE jadwal SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $id]);
            
            // Jika status diubah ke Selesai, update semua child schedules juga
            if ($status === 'Selesai') {
                $stmt = $pdo->prepare("UPDATE jadwal SET status = 'Selesai', updated_at = NOW() WHERE parent_jadwal_id = ?");
                $stmt->execute([$id]);
            }
            
            $_SESSION['success'] = "Status jadwal berhasil diubah menjadi '$status'!";
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Gagal mengubah status: ' . $e->getMessage();
        }
    }
    header("Location: schedule.php");
    exit();
}

// =========================
// DELETE JADWAL
// =========================
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    try {
        // Cek apakah jadwal bagian dari seri berulang
        $stmt = $pdo->prepare("SELECT kode_jadwal, jenis_periode, parent_jadwal_id, status FROM jadwal WHERE id = ?");
        $stmt->execute([$id]);
        $jadwal = $stmt->fetch();
        
        if ($jadwal) {
            // Cek status jadwal - tidak bisa hapus jika status Berjalan
            if ($jadwal['status'] === 'Berjalan') {
                $_SESSION['error'] = 'Tidak dapat menghapus jadwal dengan status "Berjalan"!';
                header("Location: schedule.php");
                exit();
            }
            
            // Cek apakah sudah ada laporan untuk jadwal ini
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE jadwal_id = ?");
                $stmt->execute([$id]);
                $report_count = $stmt->fetchColumn();
            
            if ($report_count > 0) {
                $_SESSION['error'] = 'Tidak dapat menghapus! Jadwal ini sudah memiliki ' . $report_count . ' laporan.';
            } else {
                // Jika jadwal memiliki parent (child schedule)
                if ($jadwal['parent_jadwal_id']) {
                    $stmt = $pdo->prepare("DELETE FROM jadwal WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['success'] = 'Child schedule berhasil dihapus!';
                } else {
                    // Jika jadwal adalah parent (atau standalone)
                    // Hapus semua child schedules terlebih dahulu (jika ada)
                    $stmt = $pdo->prepare("DELETE FROM jadwal WHERE parent_jadwal_id = ?");
                    $stmt->execute([$id]);
                    
                    // Hapus jadwal utama
                    $stmt = $pdo->prepare("DELETE FROM jadwal WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    $_SESSION['success'] = 'Jadwal berhasil dihapus!';
                }
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal menghapus jadwal: ' . $e->getMessage();
    }
    header("Location: schedule.php");
    exit();
}

// =========================
// AMBIL DATA JADWAL DENGAN FILTER
// =========================
try {
    $query = "
        SELECT 
            j.*,
            c.nama_perusahaan, c.nama_customer, c.telepon as customer_telepon,
            c.gedung, c.lantai, c.unit, c.jumlah_station, c.alamat as customer_alamat,
            s.nama_service, s.harga, s.kode_service, s.durasi_menit,
            u.nama as pekerja_nama, u.jabatan as pekerja_jabatan,
            a.nama as admin_nama,
            
            -- Hitung total laporan untuk jadwal ini
            (SELECT COUNT(*) FROM reports r WHERE r.jadwal_id = j.id) as total_laporan,
            
            -- Hitung child schedules untuk parent jadwal
            (SELECT COUNT(*) FROM jadwal j2 WHERE j2.parent_jadwal_id = j.id) as child_count,
            
            -- Progress status untuk jadwal berulang
            CASE 
                WHEN j.jenis_periode = 'Sekali' THEN 'Sekali'
                WHEN j.kunjungan_berjalan >= j.jumlah_kunjungan THEN 'Selesai Semua'
                ELSE CONCAT(j.kunjungan_berjalan, '/', j.jumlah_kunjungan, ' kunjungan')
            END as progress_status
            
        FROM jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN services s ON j.service_id = s.id
        LEFT JOIN users u ON j.pekerja_id = u.id
        LEFT JOIN admin_users a ON j.admin_id = a.id
        WHERE 1=1";
    
    $params = [];
    
    if (!empty($filter_status)) {
        $query .= " AND j.status = ?";
        $params[] = $filter_status;
    }
    
    if (!empty($filter_date_from)) {
        $query .= " AND j.tanggal >= ?";
        $params[] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $query .= " AND j.tanggal <= ?";
        $params[] = $filter_date_to;
    }
    
    if (!empty($filter_customer)) {
        $query .= " AND j.customer_id = ?";
        $params[] = (int)$filter_customer;
    }
    
    if (!empty($filter_worker)) {
        $query .= " AND j.pekerja_id = ?";
        $params[] = (int)$filter_worker;
    }
    
    if (!empty($filter_periode)) {
        $query .= " AND j.jenis_periode = ?";
        $params[] = $filter_periode;
    }
    
    // Filter untuk menampilkan parent schedules atau semua schedules
    $show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
    if (!$show_all) {
        $query .= " AND j.parent_jadwal_id IS NULL";
    }
    
    $query .= " ORDER BY 
                CASE j.status
                    WHEN 'Berjalan' THEN 1
                    WHEN 'Menunggu' THEN 2
                    WHEN 'Selesai' THEN 3
                    WHEN 'Dibatalkan' THEN 4
                    ELSE 5
                END,
                j.tanggal ASC, j.jam ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data jadwal: " . $e->getMessage();
    error_log("Schedule Query Error: " . $e->getMessage());
    $schedules = [];
}

$pageTitle = 'Kelola Jadwal';

require_once 'includes/header.php';
?>

<style>
    .schedule-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        margin-bottom: 20px;
        overflow: hidden;
    }
    .schedule-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
    }
    .schedule-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e9ecef;
        background-color: #f8f9fa;
    }
    .schedule-body {
        padding: 1.25rem;
    }
    .schedule-footer {
        padding: 1rem 1.25rem;
        border-top: 1px solid #e9ecef;
        background-color: #f8f9fa;
    }
    .status-badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.75rem;
        border-radius: 50px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-menunggu { background-color: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
    .status-berjalan { 
        background-color: #d1ecf1; 
        color: #0c5460; 
        border: 1px solid #bee5eb;
        position: relative;
        overflow: hidden;
    }
    .status-berjalan::after {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
        animation: shine 3s infinite;
    }
    .status-selesai { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .status-dibatalkan { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    @keyframes shine {
        0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
        100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
    }
    .priority-badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
        border-radius: 50px;
        font-weight: 600;
    }
    .priority-rendah { background-color: #e7f5ff; color: #0c63e4; }
    .priority-sedang { background-color: #e7fff3; color: #198754; }
    .priority-tinggi { background-color: #fff3cd; color: #856404; }
    .priority-darurat { background-color: #f8d7da; color: #721c24; }
    .info-item {
        display: flex;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }
    .info-item i {
        color: #6c757d;
        width: 20px;
        margin-top: 3px;
        margin-right: 10px;
        text-align: center;
    }
    .price-tag {
        font-size: 1.25rem;
        font-weight: 700;
        color: #198754;
        text-align: right;
    }
    .filter-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .empty-state-container {
        background-color: #f8f9fa;
        padding: 4rem;
        border-radius: 12px;
        border: 1px dashed #dee2e6;
        text-align: center;
    }
    .schedule-code {
        font-family: monospace;
        background: #f1f3f4;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.9rem;
    }
    .location-detail {
        background: #f8f9fa;
        padding: 0.75rem;
        border-radius: 8px;
        margin-top: 0.5rem;
    }
    .report-badge {
        background-color: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        margin-left: 0.5rem;
    }
    .period-badge {
        background-color: #d4edda;
        color: #155724;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
    }
    .kunjungan-info {
        background-color: #e7f5ff;
        padding: 0.5rem;
        border-radius: 6px;
        margin-top: 0.5rem;
        font-size: 0.85rem;
    }
    .child-badge {
        background-color: #f8f9fa;
        color: #6c757d;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
        border: 1px dashed #dee2e6;
    }
    .parent-badge {
        background-color: #fff3cd;
        color: #856404;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
        border: 1px solid #ffeaa7;
    }
    .station-badge {
        background-color: #e3f2fd;
        color: #1565c0;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
        font-size: 0.75rem;
        margin-left: 0.5rem;
    }
    .google-maps-badge {
        background-color: #34a853;
        color: white;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
        cursor: pointer;
    }
    .maps-preview {
        height: 200px;
        width: 100%;
        border-radius: 8px;
        border: 1px solid #dee2e6;
        overflow: hidden;
        margin-top: 0.5rem;
    }
    .customer-services-list {
        max-height: 150px;
        overflow-y: auto;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 6px;
        border: 1px solid #e9ecef;
    }
    .service-item {
        padding: 8px 10px;
        margin-bottom: 5px;
        background: white;
        border-radius: 4px;
        border-left: 4px solid #007bff;
    }
    .btn-locked {
        cursor: not-allowed;
        opacity: 0.6;
        position: relative;
    }
    .btn-locked::after {
        content: '🔒';
        position: absolute;
        top: -8px;
        right: -8px;
        font-size: 0.8rem;
        background: #fff;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #dc3545;
    }
    .running-warning {
        background-color: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 0.5rem;
        margin-bottom: 1rem;
        border-radius: 6px;
        font-size: 0.85rem;
    }
    .running-warning i {
        color: #856404;
    }
    .status-running {
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.7); }
        70% { box-shadow: 0 0 0 10px rgba(13, 110, 253, 0); }
        100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); }
    }
    .time-conflict-warning {
        background-color: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 15px;
        margin: 15px 0;
    }
    .time-conflict-warning ul {
        margin-bottom: 0;
        padding-left: 20px;
    }
    .time-conflict-warning li {
        margin-bottom: 5px;
        font-size: 0.9rem;
    }
    .worker-availability-check {
        background-color: #e7f5ff;
        padding: 10px;
        border-radius: 8px;
        margin-top: 10px;
        font-size: 0.9rem;
    }
    .worker-available {
        color: #198754;
    }
    .worker-unavailable {
        color: #dc3545;
    }
    .availability-check-btn {
        margin-top: 5px;
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
                <h1 class="h2"><i class="fas fa-calendar-alt me-2"></i><?php echo $pageTitle; ?></h1>
                <div>
                    <button type="button" id="btnTambahJadwal" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>Tambah Jadwal
                    </button>
                </div>
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

            <!-- Filter Section -->
            <div class="filter-card">
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Jadwal</h5>
                <form method="GET" action="schedule.php" class="row g-3">
                    <div class="col-md-2">
                        <label for="filter_status" class="form-label">Status</label>
                        <select class="form-select" id="filter_status" name="status">
                            <option value="">Semua Status</option>
                            <option value="Menunggu" <?php echo $filter_status === 'Menunggu' ? 'selected' : ''; ?>>Menunggu</option>
                            <option value="Berjalan" <?php echo $filter_status === 'Berjalan' ? 'selected' : ''; ?>>Berjalan</option>
                            <option value="Selesai" <?php echo $filter_status === 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                            <option value="Dibatalkan" <?php echo $filter_status === 'Dibatalkan' ? 'selected' : ''; ?>>Dibatalkan</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_date_from" class="form-label">Dari Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_from" name="date_from" 
                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="filter_date_to" class="form-label">Sampai Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_to" name="date_to" 
                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_customer" class="form-label">Customer</label>
                        <select class="form-select" id="filter_customer" name="customer">
                            <option value="">Semua Customer</option>
                            <?php foreach ($all_customers_for_filter as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" 
                                    <?php echo $filter_customer == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['display_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_periode" class="form-label">Jenis Periode</label>
                        <select class="form-select" id="filter_periode" name="periode">
                            <option value="">Semua</option>
                            <option value="Sekali" <?php echo $filter_periode === 'Sekali' ? 'selected' : ''; ?>>Sekali</option>
                            <option value="Harian" <?php echo $filter_periode === 'Harian' ? 'selected' : ''; ?>>Harian</option>
                            <option value="Mingguan" <?php echo $filter_periode === 'Mingguan' ? 'selected' : ''; ?>>Mingguan</option>
                            <option value="Bulanan" <?php echo $filter_periode === 'Bulanan' ? 'selected' : ''; ?>>Bulanan</option>
                            <option value="Tahunan" <?php echo $filter_periode === 'Tahunan' ? 'selected' : ''; ?>>Tahunan</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <div class="d-flex gap-2 w-100">
                            <button type="submit" class="btn btn-outline-primary flex-grow-1">
                                <i class="fas fa-search"></i>
                            </button>
                            <a href="schedule.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo"></i>
                            </a>
                        </div>
                    </div>
                </form>
                
                <!-- Toggle untuk menampilkan semua jadwal termasuk child -->
                <div class="mt-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="showAllSchedules" 
                           onchange="window.location.href='schedule.php?show_all=' + (this.checked ? '1' : '0')"
                           <?php echo isset($_GET['show_all']) && $_GET['show_all'] == '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="showAllSchedules">
                        Tampilkan semua jadwal (termasuk child schedules)
                    </label>
                </div>
            </div>

            <!-- Statistics -->
            <?php if (!empty($schedules)): 
                $total_schedules = count($schedules);
                $status_counts = [
                    'Menunggu' => 0,
                    'Berjalan' => 0,
                    'Selesai' => 0,
                    'Dibatalkan' => 0
                ];
                
                $period_counts = [
                    'Sekali' => 0,
                    'Harian' => 0,
                    'Mingguan' => 0,
                    'Bulanan' => 0,
                    'Tahunan' => 0
                ];
                
                $total_reports = 0;
                $total_child_schedules = 0;
                $total_stations = 0;
                $running_schedules = 0;
                
                foreach ($schedules as $schedule) {
                    if (isset($status_counts[$schedule['status']])) {
                        $status_counts[$schedule['status']]++;
                    }
                    if (isset($period_counts[$schedule['jenis_periode']])) {
                        $period_counts[$schedule['jenis_periode']]++;
                    }
                    $total_reports += $schedule['total_laporan'] ?? 0;
                    $total_child_schedules += $schedule['child_count'] ?? 0;
                    $total_stations += $schedule['jumlah_station'] ?? 0;
                    
                    if ($schedule['status'] === 'Berjalan') {
                        $running_schedules++;
                    }
                }
            ?>
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h6 class="card-title">Total Jadwal</h6>
                            <h3 class="mb-0"><?php echo $total_schedules; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-warning text-dark">
                        <div class="card-body">
                            <h6 class="card-title">Menunggu</h6>
                            <h3 class="mb-0"><?php echo $status_counts['Menunggu']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white <?php echo $running_schedules > 0 ? 'status-running' : ''; ?>">
                        <div class="card-body">
                            <h6 class="card-title">Berjalan</h6>
                            <h3 class="mb-0"><?php echo $status_counts['Berjalan']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h6 class="card-title">Selesai</h6>
                            <h3 class="mb-0"><?php echo $status_counts['Selesai']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-light text-dark">
                        <div class="card-body">
                            <h6 class="card-title">Laporan</h6>
                            <h3 class="mb-0"><?php echo $total_reports; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h6 class="card-title">Total Station</h6>
                            <h3 class="mb-0"><?php echo $total_stations; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($running_schedules > 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Catatan:</strong> Ada <strong><?php echo $running_schedules; ?></strong> jadwal dengan status "Berjalan". 
                Jadwal dengan status ini <strong>tidak dapat diedit atau dihapus</strong> hingga status diubah.
            </div>
            <?php endif; ?>
            
            <?php endif; ?>

            <!-- Schedule List -->
            <div class="row">
                <?php if (empty($schedules)): ?>
                    <div class="col">
                        <div class="text-center empty-state-container">
                            <i class="fas fa-calendar-times fa-4x text-muted mb-4"></i>
                            <h4 class="text-dark fw-bold">Belum Ada Jadwal</h4>
                            <p class="text-muted">Tekan tombol "Tambah Jadwal" untuk membuat jadwal baru.</p>
                            <button type="button" id="btnTambahJadwal2" class="btn btn-primary mt-3">
                                <i class="fas fa-plus-circle me-2"></i>Tambah Jadwal Pertama
                            </button>
                        </div>
                    </div>
                <?php else: 
                    foreach ($schedules as $schedule): 
                        // Format data
                        $tanggal_formatted = formatTanggalIndonesia($schedule['tanggal']);
                        $jam_formatted = date('H:i', strtotime($schedule['jam']));
                        $harga_formatted = 'Rp ' . number_format($schedule['harga'], 0, ',', '.');
                        $status_class = 'status-' . strtolower($schedule['status']);
                        $priority_class = 'priority-' . strtolower($schedule['prioritas'] ?? 'sedang');
                        
                        // Lokasi detail dari customer
                        $lokasi_detail = '';
                        if (!empty($schedule['customer_alamat'])) $lokasi_detail .= $schedule['customer_alamat'];
                        if (!empty($schedule['gedung'])) $lokasi_detail .= ' - ' . $schedule['gedung'];
                        if (!empty($schedule['lantai'])) $lokasi_detail .= ', Lt. ' . $schedule['lantai'];
                        if (!empty($schedule['unit'])) $lokasi_detail .= ', ' . $schedule['unit'];
                        
                        // Info kunjungan
                        $is_recurring = $schedule['jenis_periode'] !== 'Sekali';
                        $is_parent = empty($schedule['parent_jadwal_id']);
                        $is_child = !$is_parent;
                        $jumlah_station = $schedule['jumlah_station'] ?? 0;
                        $jumlah_kunjungan = $schedule['jumlah_kunjungan'] ?? 1;
                        
                        // Cek apakah bisa diedit dan dihapus
                        $can_edit = canEditSchedule($schedule['id'], $pdo);
                        $can_delete = canDeleteSchedule($schedule['id'], $pdo);
                        
                        // Google Maps badge
                        $maps_badge = '';
                        if (!empty($schedule['google_maps_url'])) {
                            $maps_badge = '<span class="google-maps-badge" onclick="openMaps(\'' . htmlspecialchars($schedule['google_maps_url']) . '\')">
                                            <i class="fas fa-map-marker-alt me-1"></i>Maps
                                          </span>';
                        }
                        
                        // Station info
                        $station_info = '';
                        if ($jumlah_station > 0 && $schedule['jenis_periode'] === 'Sekali') {
                            $station_info = '<span class="station-badge"><i class="fas fa-map-pin me-1"></i>' . $jumlah_station . ' Station</span>';
                        }
                    
                    ?>
                        <div class="col-xl-6 col-lg-12 mb-4">
                            <div class="schedule-card <?php echo $schedule['status'] === 'Berjalan' ? 'border-warning' : ''; ?>">
                                <div class="schedule-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($schedule['nama_perusahaan']); ?>
                                            <?php if ($schedule['total_laporan'] > 0): ?>
                                                <span class="report-badge" title="<?php echo $schedule['total_laporan']; ?> laporan dibuat">
                                                    <i class="fas fa-file-alt me-1"></i><?php echo $schedule['total_laporan']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($is_recurring): ?>
                                                <span class="period-badge">
                                                    <i class="fas fa-redo me-1"></i><?php echo $schedule['jenis_periode']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($is_child): ?>
                                                <span class="child-badge" title="Child Schedule">
                                                    <i class="fas fa-level-down-alt me-1"></i>Child
                                                </span>
                                            <?php elseif ($schedule['child_count'] > 0): ?>
                                                <span class="parent-badge" title="Parent Schedule">
                                                    <i class="fas fa-level-up-alt me-1"></i>Parent (<?php echo $schedule['child_count']; ?>)
                                                </span>
                                            <?php endif; ?>
                                            <?php echo $station_info; ?>
                                            <?php echo $maps_badge; ?>
                                            <?php if ($schedule['status'] === 'Berjalan'): ?>
                                                <span class="badge bg-warning text-dark ms-1">
                                                    <i class="fas fa-play-circle me-1"></i>Sedang Berjalan
                                                </span>
                                            <?php endif; ?>
                                        </h5>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="schedule-code"><?php echo htmlspecialchars($schedule['kode_jadwal'] ?? 'JDW/XXXX/XX/XXX'); ?></span>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo htmlspecialchars($schedule['nama_customer']); ?>
                                            </span>
                                            <span class="priority-badge <?php echo $priority_class; ?>">
                                                <?php echo htmlspecialchars($schedule['prioritas'] ?? 'Sedang'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($schedule['status']); ?>
                                    </span>
                                </div>
                                
                                <div class="schedule-body">
                                    <?php if ($schedule['status'] === 'Berjalan'): ?>
                                    <div class="running-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Jadwal sedang berjalan!</strong> Tidak dapat diedit atau dihapus hingga status diubah.
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_recurring && $jumlah_kunjungan > 1): ?>
                                    <div class="kunjungan-info mb-3">
                                        <i class="fas fa-calendar-check me-1"></i>
                                        Jadwal <?php echo strtolower($schedule['jenis_periode']); ?> - 
                                        <?php echo $schedule['kunjungan_berjalan'] ?? 0; ?> dari <?php echo $jumlah_kunjungan; ?> kunjungan selesai
                                    </div>
                                    <?php elseif ($jumlah_station > 0 && $schedule['jenis_periode'] === 'Sekali'): ?>
                                    <div class="kunjungan-info mb-3">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        <?php echo $jumlah_station; ?> Station Inspeksi - 
                                        <?php echo $schedule['kunjungan_berjalan'] ?? 0; ?> dari <?php echo $jumlah_kunjungan; ?> laporan dibuat
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="info-item">
                                                <i class="fas fa-calendar-day"></i>
                                                <div>
                                                    <strong>Tanggal & Jam:</strong><br>
                                                    <?php echo $tanggal_formatted . ' • ' . $jam_formatted; ?>
                                                    <?php if (!empty($schedule['durasi_estimasi'])): ?>
                                                        <br><small class="text-muted">Durasi: <?php echo $schedule['durasi_estimasi']; ?> menit</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-cog"></i>
                                                <div>
                                                    <strong>Layanan:</strong><br>
                                                    <?php echo htmlspecialchars($schedule['nama_service']); ?>
                                                    <?php if (!empty($schedule['kode_service'])): ?>
                                                        <small class="text-muted">(<?php echo htmlspecialchars($schedule['kode_service']); ?>)</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-user-tie"></i>
                                                <div>
                                                    <strong>Pekerja:</strong><br>
                                                    <?php if (!empty($schedule['pekerja_nama'])): ?>
                                                        <?php echo htmlspecialchars($schedule['pekerja_nama']); ?>
                                                        <?php if (!empty($schedule['pekerja_jabatan'])): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($schedule['pekerja_jabatan']); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">- Belum ditugaskan -</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <div>
                                                    <strong>Lokasi:</strong><br>
                                                    <?php if (!empty($schedule['lokasi'])): ?>
                                                        <?php echo nl2br(htmlspecialchars($schedule['lokasi'])); ?>
                                                    <?php elseif (!empty($lokasi_detail)): ?>
                                                        <?php echo nl2br(htmlspecialchars($lokasi_detail)); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Tidak ada informasi lokasi</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($schedule['google_maps_url'])): ?>
                                                        <div class="mt-1">
                                                            <a href="<?php echo htmlspecialchars($schedule['google_maps_url']); ?>" 
                                                               target="_blank" class="btn btn-sm btn-outline-success">
                                                                <i class="fas fa-external-link-alt me-1"></i>Buka di Google Maps
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($jumlah_station > 0): ?>
                                                        <div class="location-detail mt-1">
                                                            <small><i class="fas fa-map-pin me-1"></i><?php echo $jumlah_station; ?> Station Inspeksi</small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($schedule['catatan_admin'])): ?>
                                            <div class="info-item">
                                                <i class="fas fa-clipboard-list"></i>
                                                <div>
                                                    <strong>Catatan:</strong><br>
                                                    <?php echo nl2br(htmlspecialchars($schedule['catatan_admin'])); ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="price-tag mb-3">
                                                <?php echo $harga_formatted; ?>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-phone"></i>
                                                <div>
                                                    <small>Telp: <?php echo htmlspecialchars($schedule['customer_telepon']); ?></small>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-user-cog"></i>
                                                <div>
                                                    <small>Dibuat oleh: <?php echo htmlspecialchars($schedule['admin_nama']); ?></small>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($schedule['created_at'])): ?>
                                            <div class="info-item">
                                                <i class="fas fa-clock"></i>
                                                <div>
                                                    <small>Dibuat: <?php echo date('d/m/Y H:i', strtotime($schedule['created_at'])); ?></small>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="schedule-footer">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex gap-2">
                                            <?php if ($is_child): ?>
                                                <span class="text-muted small">Child schedule - edit melalui parent</span>
                                            <?php else: ?>
                                                <?php if ($can_edit): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit" 
                                                            data-id="<?php echo $schedule['id']; ?>"
                                                            data-customer_id="<?php echo $schedule['customer_id']; ?>"
                                                            data-pekerja_id="<?php echo $schedule['pekerja_id']; ?>"
                                                            data-tanggal="<?php echo $schedule['tanggal']; ?>"
                                                            data-jam="<?php echo $schedule['jam']; ?>"
                                                            data-lokasi="<?php echo htmlspecialchars($schedule['lokasi'] ?? ''); ?>"
                                                            data-google_maps_url="<?php echo htmlspecialchars($schedule['google_maps_url'] ?? ''); ?>"
                                                            data-catatan_admin="<?php echo htmlspecialchars($schedule['catatan_admin'] ?? ''); ?>"
                                                            data-prioritas="<?php echo htmlspecialchars($schedule['prioritas'] ?? ''); ?>"
                                                            data-durasi_estimasi="<?php echo $schedule['durasi_estimasi'] ?? ''; ?>"
                                                            data-jenis_periode="<?php echo htmlspecialchars($schedule['jenis_periode'] ?? ''); ?>"
                                                            data-jumlah_kunjungan="<?php echo $schedule['jumlah_kunjungan'] ?? ''; ?>">
                                                        <i class="fas fa-edit me-1"></i>Edit
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-locked" disabled
                                                            title="Jadwal dengan status 'Berjalan' tidak dapat diedit">
                                                        <i class="fas fa-edit me-1"></i>Edit (Terkunci)
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                                    <i class="fas fa-cog me-1"></i>Aksi
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <?php if ($schedule['status'] === 'Menunggu'): ?>
                                                        <li><a class="dropdown-item" href="?update_status=Berjalan&id=<?php echo $schedule['id']; ?>">
                                                            <i class="fas fa-play-circle me-1"></i>Mulai Jadwal (Set Berjalan)
                                                        </a></li>
                                                    <?php elseif ($schedule['status'] === 'Berjalan'): ?>
                                                        <li><a class="dropdown-item" href="?update_status=Selesai&id=<?php echo $schedule['id']; ?>">
                                                            <i class="fas fa-check-circle me-1"></i>Tandai Selesai
                                                        </a></li>
                                                    <?php else: ?>
                                                        <?php if ($schedule['status'] !== 'Selesai'): ?>
                                                        <li><a class="dropdown-item" href="?update_status=Menunggu&id=<?php echo $schedule['id']; ?>">
                                                            <i class="fas fa-clock me-1"></i>Set Menunggu
                                                        </a></li>
                                                        <?php endif; ?>
                                                        <?php if ($schedule['status'] !== 'Berjalan'): ?>
                                                        <li><a class="dropdown-item" href="?update_status=Berjalan&id=<?php echo $schedule['id']; ?>">
                                                            <i class="fas fa-play-circle me-1"></i>Set Berjalan
                                                        </a></li>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($schedule['status'] !== 'Selesai'): ?>
                                                    <li><a class="dropdown-item" href="?update_status=Selesai&id=<?php echo $schedule['id']; ?>">
                                                        <i class="fas fa-check-circle me-1"></i>Set Selesai
                                                    </a></li>
                                                    <?php endif; ?>
                                                    
                                                    <li><hr class="dropdown-divider"></li>
                                                    
                                                    <?php if ($schedule['total_laporan'] > 0): ?>
                                                    <li><a class="dropdown-item" href="reports.php?schedule_id=<?php echo $schedule['id']; ?>">
                                                        <i class="fas fa-file-alt me-1"></i>Lihat Laporan (<?php echo $schedule['total_laporan']; ?>)
                                                    </a></li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($schedule['status'] !== 'Berjalan' && $schedule['status'] !== 'Selesai'): ?>
                                                    <li><a class="dropdown-item text-danger" href="?update_status=Dibatalkan&id=<?php echo $schedule['id']; ?>">
                                                        <i class="fas fa-times-circle me-1"></i>Batalkan Jadwal
                                                    </a></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex gap-2">
                                            <?php if ($can_delete): ?>
                                            <a href="?delete=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-outline-danger" 
                                               onclick="return confirm('Hapus jadwal ini? Tindakan ini tidak dapat dibatalkan.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger btn-locked" disabled 
                                                    title="<?php 
                                                        if ($schedule['status'] === 'Berjalan') {
                                                            echo 'Tidak dapat dihapus karena jadwal sedang berjalan';
                                                        } elseif ($schedule['total_laporan'] > 0) {
                                                            echo 'Tidak dapat dihapus karena sudah memiliki laporan';
                                                        } elseif ($schedule['status'] === 'Selesai') {
                                                            echo 'Tidak dapat dihapus karena jadwal sudah selesai';
                                                        }
                                                    ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit Jadwal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="scheduleForm" method="POST" action="schedule.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="scheduleModalLabel">Tambah Jadwal Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="formScheduleId">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                            <select class="form-select" id="customer_id" name="customer_id" required>
                                <option value="">Pilih Customer</option>
                                <?php 
                                foreach ($customers as $customer): 
                                    $services_list = $customer['services_list'] ?? 'Belum ada layanan';
                                ?>
                                    <option value="<?php echo $customer['id']; ?>" 
                                            data-telepon="<?php echo htmlspecialchars($customer['telepon']); ?>"
                                            data-alamat="<?php echo htmlspecialchars($customer['alamat'] ?? ''); ?>"
                                            data-gedung="<?php echo htmlspecialchars($customer['gedung'] ?? ''); ?>"
                                            data-lantai="<?php echo htmlspecialchars($customer['lantai'] ?? ''); ?>"
                                            data-unit="<?php echo htmlspecialchars($customer['unit'] ?? ''); ?>"
                                            data-jumlah_station="<?php echo htmlspecialchars($customer['jumlah_station'] ?? 0); ?>"
                                            data-services_list="<?php echo htmlspecialchars($services_list); ?>"
                                            data-service_ids="<?php echo htmlspecialchars($customer['service_ids'] ?? ''); ?>"
                                            data-total_services="<?php echo htmlspecialchars($customer['total_services'] ?? 0); ?>">
                                        <?php echo htmlspecialchars($customer['display_name']); ?>
                                        <?php if (!empty($customer['jumlah_station']) && $customer['jumlah_station'] > 0): ?>
                                            (<?php echo $customer['jumlah_station']; ?> station)
                                        <?php endif; ?>
                                        <?php if (!empty($customer['total_services']) && $customer['total_services'] > 0): ?>
                                            <small class="text-muted"> - <?php echo $customer['total_services']; ?> layanan</small>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="google_maps_url" class="form-label">Google Maps Link</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-map-marked-alt"></i>
                                </span>
                                <input type="url" class="form-control" id="google_maps_url" 
                                       name="google_maps_url" placeholder="https://maps.google.com/?q=..."
                                       pattern="https?://.*">
                                <button type="button" class="btn btn-outline-secondary" id="btnOpenMap">
                                    <i class="fas fa-external-link-alt"></i>
                                </button>
                            </div>
                            <small class="text-muted">
                                Paste link Google Maps atau gunakan tombol untuk mencari lokasi
                            </small>
                        </div>
                    </div>

                    <!-- Info Layanan Customer -->
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><strong>Layanan Customer:</strong></span>
                                    <span id="customerServicesList" class="fw-bold">
                                        Pilih customer untuk melihat layanan
                                    </span>
                                </div>
                                <div id="servicesDetails" class="mt-2">
                                    <div class="customer-services-list">
                                        <small class="text-muted">Layanan akan tampil setelah memilih customer</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Google Maps Preview -->
                    <div class="row g-3 mb-3" id="mapsPreviewContainer" style="display: none;">
                        <div class="col-12">
                            <label class="form-label">Preview Lokasi Google Maps</label>
                            <div class="maps-preview" id="mapsPreview">
                                <iframe src="" style="width: 100%; height: 100%; border: 0;" 
                                        allowfullscreen loading="lazy" 
                                        referrerpolicy="no-referrer-when-downgrade"></iframe>
                            </div>
                            <small class="text-muted">Preview akan muncul setelah memasukkan link Google Maps</small>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label for="tanggal" class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="tanggal" name="tanggal" required 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="jam" class="form-label">Jam <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="jam" name="jam" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="prioritas" class="form-label">Prioritas</label>
                            <select class="form-select" id="prioritas" name="prioritas">
                                <option value="Sedang">Sedang</option>
                                <option value="Rendah">Rendah</option>
                                <option value="Tinggi">Tinggi</option>
                                <option value="Darurat">Darurat</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="durasi_estimasi" class="form-label">Durasi (menit)</label>
                            <input type="number" class="form-control" id="durasi_estimasi" name="durasi_estimasi" 
                                   min="15" max="480" step="15" placeholder="Auto dari layanan">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="jenis_periode" class="form-label">Jenis Jadwal</label>
                            <select class="form-select" id="jenis_periode" name="jenis_periode">
                                <option value="Sekali">Sekali</option>
                                <option value="Harian">Harian</option>
                                <option value="Mingguan">Mingguan</option>
                                <option value="Bulanan">Bulanan</option>
                                <option value="Tahunan">Tahunan</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="jumlah_kunjungan" class="form-label">Jumlah Station</label>
                            <input type="number" class="form-control" id="jumlah_kunjungan" name="jumlah_kunjungan" 
                                   min="1" max="100" value="1" readonly>
                            <small class="text-muted">Akan otomatis sesuai jumlah station customer</small>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="pekerja_id" class="form-label">Pekerja</label>
                            <select class="form-select" id="pekerja_id" name="pekerja_id">
                                <option value="">Pilih Pekerja (Opsional)</option>
                                <?php foreach ($workers as $worker): ?>
                                    <option value="<?php echo $worker['id']; ?>">
                                        <?php echo htmlspecialchars($worker['nama']); ?> 
                                        (<?php echo htmlspecialchars($worker['jabatan']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Worker Availability Check -->
                    <div class="row g-3 mb-3" id="workerAvailabilitySection" style="display: none;">
                        <div class="col-12">
                            <div class="worker-availability-check" id="workerAvailabilityCheck">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span id="availabilityMessage">Pilih pekerja, tanggal, dan jam untuk mengecek ketersediaan</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary availability-check-btn" 
                                            id="checkAvailabilityBtn">
                                        <i class="fas fa-sync-alt me-1"></i>Cek Ketersediaan
                                    </button>
                                </div>
                                <div id="availabilityResult" class="mt-2"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="lokasi" class="form-label">Lokasi Detail (Opsional)</label>
                        <textarea class="form-control" id="lokasi" name="lokasi" rows="2" 
                                  placeholder="Masukkan alamat detail atau titik temu..."></textarea>
                        <small class="text-muted">Kosongkan untuk menggunakan alamat dari data customer</small>
                    </div>

                    <div class="mb-3">
                        <label for="catatan_admin" class="form-label">Catatan Admin</label>
                        <textarea class="form-control" id="catatan_admin" name="catatan_admin" rows="3" 
                                  placeholder="Catatan khusus untuk pekerja..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
// Function untuk membuka Google Maps di tab baru
function openMaps(url) {
    window.open(url, '_blank');
}

document.addEventListener("DOMContentLoaded", function() {
    const scheduleModalEl = document.getElementById('scheduleModal');
    const scheduleModal = new bootstrap.Modal(scheduleModalEl);
    const form = document.getElementById('scheduleForm');
    const modalTitle = document.getElementById('scheduleModalLabel');
    const submitButton = form.querySelector('button[type="submit"]');
    const formAction = document.getElementById('formAction');
    const formScheduleId = document.getElementById('formScheduleId');
    
    // Element baru
    const customerServicesList = document.getElementById('customerServicesList');
    const servicesDetails = document.querySelector('.customer-services-list');
    const googleMapsUrlInput = document.getElementById('google_maps_url');
    const mapsPreviewContainer = document.getElementById('mapsPreviewContainer');
    const mapsPreviewIframe = document.querySelector('#mapsPreview iframe');
    const btnOpenMap = document.getElementById('btnOpenMap');
    const lokasiTextarea = document.getElementById('lokasi');
    
    // Referensi lama
    const customerSelect = document.getElementById('customer_id');
    const jenisPeriodeSelect = document.getElementById('jenis_periode');
    const jumlahKunjunganInput = document.getElementById('jumlah_kunjungan');
    const tanggalInput = document.getElementById('tanggal');
    const jamInput = document.getElementById('jam');
    const durasiEstimasiInput = document.getElementById('durasi_estimasi');
    const pekerjaSelect = document.getElementById('pekerja_id');
    
    // Worker availability elements
    const workerAvailabilitySection = document.getElementById('workerAvailabilitySection');
    const availabilityMessage = document.getElementById('availabilityMessage');
    const availabilityResult = document.getElementById('availabilityResult');
    const checkAvailabilityBtn = document.getElementById('checkAvailabilityBtn');
    
    // Set default tanggal ke hari ini
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    tanggalInput.value = todayStr;
    
    // Set default jam ke 08:00
    jamInput.value = '08:00';
    
    // Event Listeners
    document.getElementById('btnTambahJadwal').addEventListener('click', setupAddModal);
    document.getElementById('btnTambahJadwal2')?.addEventListener('click', setupAddModal);
    
    document.querySelectorAll('.btn-edit').forEach(button => {
        button.addEventListener('click', function() { 
            setupEditModal(this); 
        });
    });
    
    // Customer select change
    customerSelect.addEventListener('change', function() {
        updateCustomerInfo();
        updateCustomerServices();
        updateLokasiFromCustomer();
        updateJumlahKunjungan();
        toggleWorkerAvailabilitySection();
    });
    
    // Google Maps URL change
    googleMapsUrlInput.addEventListener('input', function() {
        updateGoogleMapsPreview();
    });
    
    // Open map button
    btnOpenMap.addEventListener('click', function() {
        openGoogleMapsPicker();
    });
    
    // Jenis periode change
    jenisPeriodeSelect.addEventListener('change', function() {
        updateJumlahKunjungan();
    });
    
    // Pekerja, tanggal, jam change untuk availability check
    pekerjaSelect.addEventListener('change', toggleWorkerAvailabilitySection);
    tanggalInput.addEventListener('change', toggleWorkerAvailabilitySection);
    jamInput.addEventListener('change', toggleWorkerAvailabilitySection);
    durasiEstimasiInput.addEventListener('change', toggleWorkerAvailabilitySection);
    
    // Check availability button
    checkAvailabilityBtn.addEventListener('click', checkWorkerAvailability);
    
    // Functions
    function setupAddModal() {
        form.reset();
        modalTitle.textContent = 'Tambah Jadwal Baru';
        submitButton.textContent = 'Simpan Jadwal';
        submitButton.classList.remove('btn-warning');
        submitButton.classList.add('btn-primary');
        formAction.value = 'create';
        formScheduleId.value = '';
        
        // Set default values
        tanggalInput.value = todayStr;
        tanggalInput.min = todayStr;
        jamInput.value = '08:00';
        document.getElementById('prioritas').value = 'Sedang';
        document.getElementById('jenis_periode').value = 'Sekali';
        jumlahKunjunganInput.value = 1;
        customerSelect.value = '';
        pekerjaSelect.value = '';
        durasiEstimasiInput.value = '';
        googleMapsUrlInput.value = '';
        lokasiTextarea.value = '';
        document.getElementById('catatan_admin').value = '';
        
        updateCustomerInfo();
        updateCustomerServices();
        updateGoogleMapsPreview();
        updateJumlahKunjungan();
        toggleWorkerAvailabilitySection();
        clearAvailabilityResult();
        
        scheduleModal.show();
    }

    function setupEditModal(button) {
        const data = button.dataset;
        
        // Cek apakah jadwal sedang berjalan dengan melihat status badge
        const scheduleCard = button.closest('.schedule-card');
        const statusBadge = scheduleCard.querySelector('.status-badge');
        
        if (statusBadge && statusBadge.textContent.trim() === 'Berjalan') {
            alert('⚠️ Jadwal dengan status "Berjalan" tidak dapat diedit.\n\nSilakan ubah status jadwal menjadi "Menunggu" terlebih dahulu melalui menu aksi.');
            return;
        }
        
        form.reset();
        modalTitle.textContent = 'Edit Jadwal';
        submitButton.textContent = 'Update Jadwal';
        submitButton.classList.remove('btn-primary');
        submitButton.classList.add('btn-warning');
        formAction.value = 'update';
        formScheduleId.value = data.id;
        
        // Set values from data attributes
        customerSelect.value = data.customer_id || '';
        pekerjaSelect.value = data.pekerja_id || '';
        tanggalInput.value = data.tanggal || todayStr;
        tanggalInput.min = data.tanggal || todayStr;
        jamInput.value = data.jam || '08:00';
        document.getElementById('prioritas').value = data.prioritas || 'Sedang';
        durasiEstimasiInput.value = data.durasi_estimasi || '';
        document.getElementById('jenis_periode').value = data.jenis_periode || 'Sekali';
        jumlahKunjunganInput.value = data.jumlah_kunjungan || 1;
        lokasiTextarea.value = data.lokasi || '';
        googleMapsUrlInput.value = data.google_maps_url || '';
        document.getElementById('catatan_admin').value = data.catatan_admin || '';
        
        // Trigger change events untuk update preview
        updateCustomerInfo();
        updateCustomerServices();
        updateGoogleMapsPreview();
        updateJumlahKunjungan();
        toggleWorkerAvailabilitySection();
        clearAvailabilityResult();
        
        scheduleModal.show();
    }

    function updateCustomerInfo() {
        const select = customerSelect;
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value) {
            // Ambil data untuk display info jika diperlukan
            const telepon = selectedOption.dataset.telepon || '-';
            const alamat = selectedOption.dataset.alamat || '-';
            
            // Update lokasi textarea jika kosong
            if (!lokasiTextarea.value.trim() && alamat !== '-') {
                lokasiTextarea.value = alamat;
            }
        }
    }

    function updateCustomerServices() {
        const select = customerSelect;
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value) {
            const servicesList = selectedOption.dataset.services_list || 'Belum ada layanan';
            const totalServices = selectedOption.dataset.total_services || '0';
            const serviceIds = selectedOption.dataset.service_ids || '';
            
            customerServicesList.textContent = `${servicesList} (${totalServices} layanan)`;
            
            // Jika ada service IDs, ambil detail layanan
            if (serviceIds) {
                const serviceIdsArray = serviceIds.split(',');
                
                // Cari layanan dari data services yang sudah dimuat
                let html = '';
                let servicesFound = false;
                
                <?php 
                // Pass PHP services data to JavaScript
                echo 'const allServices = ' . json_encode($services) . ';';
                ?>
                
                serviceIdsArray.forEach(serviceId => {
                    const service = allServices.find(s => s.id == serviceId);
                    if (service) {
                        servicesFound = true;
                        // Format harga
                        const hargaFormatted = new Intl.NumberFormat('id-ID', {
                            style: 'currency',
                            currency: 'IDR',
                            minimumFractionDigits: 0
                        }).format(service.harga);
                        
                        html += `
                            <div class="service-item">
                                <strong>${service.nama_service}</strong>
                                <div class="small text-muted">
                                    Kode: ${service.kode_service} | 
                                    Harga: ${hargaFormatted} | 
                                    Durasi: ${service.durasi_menit} menit
                                </div>
                            </div>
                        `;
                    }
                });
                
                if (servicesFound) {
                    servicesDetails.innerHTML = html;
                    
                    // Set durasi estimasi otomatis dari layanan pertama
                    const firstService = allServices.find(s => s.id == serviceIdsArray[0]);
                    if (firstService && firstService.durasi_menit && !durasiEstimasiInput.value) {
                        durasiEstimasiInput.value = firstService.durasi_menit;
                    }
                } else {
                    servicesDetails.innerHTML = '<div class="alert alert-warning py-2 mb-0">Detail layanan tidak ditemukan</div>';
                }
            } else {
                servicesDetails.innerHTML = '<div class="alert alert-warning py-2 mb-0">Customer belum memiliki layanan aktif</div>';
            }
        } else {
            customerServicesList.textContent = 'Pilih customer untuk melihat layanan';
            servicesDetails.innerHTML = '<small class="text-muted">Layanan akan tampil setelah memilih customer</small>';
        }
    }

    function updateLokasiFromCustomer() {
        const select = customerSelect;
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value && !lokasiTextarea.value.trim()) {
            const alamat = selectedOption.dataset.alamat || '';
            const gedung = selectedOption.dataset.gedung || '';
            const lantai = selectedOption.dataset.lantai || '';
            const unit = selectedOption.dataset.unit || '';
            
            let lokasiValue = alamat;
            if (gedung) lokasiValue += (lokasiValue ? ', ' : '') + gedung;
            if (lantai) lokasiValue += (lokasiValue ? ', ' : '') + 'Lantai ' + lantai;
            if (unit) lokasiValue += (lokasiValue ? ', ' : '') + unit;
            
            if (lokasiValue) {
                lokasiTextarea.value = lokasiValue;
            }
        }
    }

    function updateGoogleMapsPreview() {
        const url = googleMapsUrlInput.value.trim();
        
        if (url && isValidGoogleMapsUrl(url)) {
            // Convert URL to embed URL jika perlu
            const embedUrl = convertToEmbedUrl(url);
            mapsPreviewIframe.src = embedUrl;
            mapsPreviewContainer.style.display = 'block';
        } else {
            mapsPreviewContainer.style.display = 'none';
            mapsPreviewIframe.src = '';
        }
    }

    function isValidGoogleMapsUrl(url) {
        return url.includes('google.com/maps') || 
               url.includes('goo.gl/maps') ||
               url.includes('maps.app.goo.gl') ||
               url.startsWith('https://maps.app.goo.gl/');
    }

    function convertToEmbedUrl(url) {
        // Untuk Google Maps embed, kita bisa menggunakan place view
        if (url.includes('/maps/embed?')) {
            return url;
        }
        
        // Extract place ID atau coordinates
        let embedUrl = 'https://www.google.com/maps/embed/v1/place?';
        
        // Coba extract place ID
        if (url.includes('place/')) {
            const placeMatch = url.match(/place\/([^\/\?]+)/);
            if (placeMatch && placeMatch[1]) {
                embedUrl += `q=${encodeURIComponent(placeMatch[1])}`;
            }
        } 
        // Coba extract coordinates
        else if (url.includes('@')) {
            const coordMatch = url.match(/@([-\d.]+),([-\d.]+)/);
            if (coordMatch && coordMatch[1] && coordMatch[2]) {
                embedUrl += `center=${coordMatch[1]},${coordMatch[2]}&zoom=15`;
            }
        }
        // Default: show search view
        else {
            // Extract search query
            const searchMatch = url.match(/\/search\/([^\/\?]+)/) || 
                               url.match(/q=([^&]+)/);
            if (searchMatch && searchMatch[1]) {
                embedUrl += `q=${encodeURIComponent(searchMatch[1])}`;
            } else {
                // Jika tidak bisa parse, gunakan view biasa
                embedUrl = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d126915.50018997738!2d106.6894282539609!3d-6.229728000000001!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e69f3e945e34b9d%3A0x5371bf0fdad786a2!2sJakarta%2C%20Daerah%20Khusus%20Ibukota%20Jakarta!5e0!3m2!1sid!2sid!4v1632918475882!5m2!1sid!2sid';
            }
        }
        
        embedUrl += '&key=AIzaSyBFw0Qbyq9zTFTd-tUY6dZWTgaQzuU17R8'; // API Key contoh
        return embedUrl;
    }

    function openGoogleMapsPicker() {
        // Ambil lokasi dari form atau customer
        let searchQuery = lokasiTextarea.value.trim();
        
        if (!searchQuery) {
            const selectedOption = customerSelect.options[customerSelect.selectedIndex];
            if (selectedOption.value) {
                const alamat = selectedOption.dataset.alamat || '';
                const gedung = selectedOption.dataset.gedung || '';
                
                if (alamat) searchQuery = alamat;
                if (gedung) searchQuery += (searchQuery ? ', ' : '') + gedung;
            }
        }
        
        if (!searchQuery) {
            searchQuery = 'Jakarta, Indonesia';
        }
        
        // Encode query untuk URL
        const encodedQuery = encodeURIComponent(searchQuery);
        const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${encodedQuery}`;
        
        // Buka di tab baru
        window.open(mapsUrl, '_blank', 'noopener,noreferrer');
        
        // Beri petunjuk
        alert('1. Google Maps telah terbuka di tab baru\n2. Cari dan pilih lokasi yang tepat\n3. Klik tombol "Share" atau "Bagikan"\n4. Pilih "Copy link"\n5. Kembali ke form ini dan paste link di field Google Maps');
    }

    function updateJumlahKunjungan() {
        const select = customerSelect;
        const selectedOption = select.options[select.selectedIndex];
        const jenisPeriode = jenisPeriodeSelect.value;
        
        if (selectedOption.value) {
            const jumlah_station = parseInt(selectedOption.dataset.jumlah_station || 0);
            
            if (jenisPeriode === 'Sekali' && jumlah_station > 0) {
                // Jika jadwal sekali dan customer punya station, gunakan jumlah station
                jumlahKunjunganInput.value = jumlah_station;
                jumlahKunjunganInput.readOnly = true;
                jumlahKunjunganInput.title = 'Jumlah station dari customer: ' + jumlah_station;
            } else if (jenisPeriode === 'Sekali') {
                // Jika jadwal sekali tapi tidak ada station
                jumlahKunjunganInput.value = 1;
                jumlahKunjunganInput.readOnly = true;
                jumlahKunjunganInput.title = 'Jadwal sekali hanya untuk 1 kunjungan';
            } else {
                // Jika jadwal berulang
                jumlahKunjunganInput.readOnly = false;
                jumlahKunjunganInput.title = '';
                if (jumlahKunjunganInput.value < 2) {
                    jumlahKunjunganInput.value = 2;
                }
            }
        } else {
            // Reset jika tidak ada customer yang dipilih
            jumlahKunjunganInput.value = 1;
            jumlahKunjunganInput.readOnly = true;
            jumlahKunjunganInput.title = '';
        }
    }

    function toggleWorkerAvailabilitySection() {
        const pekerjaId = pekerjaSelect.value;
        const tanggal = tanggalInput.value;
        const jam = jamInput.value;
        
        if (pekerjaId && tanggal && jam) {
            workerAvailabilitySection.style.display = 'block';
            availabilityMessage.textContent = 'Pekerja dipilih, klik tombol untuk mengecek ketersediaan';
        } else {
            workerAvailabilitySection.style.display = 'none';
            clearAvailabilityResult();
        }
    }

    function checkWorkerAvailability() {
        const pekerjaId = pekerjaSelect.value;
        const tanggal = tanggalInput.value;
        const jam = jamInput.value;
        const durasi = durasiEstimasiInput.value || 120; // Default 2 jam
        const scheduleId = formScheduleId.value;
        
        if (!pekerjaId || !tanggal || !jam) {
            showAvailabilityResult('error', 'Harap pilih pekerja, tanggal, dan jam terlebih dahulu');
            return;
        }
        
        // Show loading
        availabilityResult.innerHTML = `
            <div class="text-center">
                <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                Mengecek ketersediaan pekerja...
            </div>
        `;
        
        // Send AJAX request
        fetch(`ajax_check_worker_availability.php?worker_id=${pekerjaId}&tanggal=${tanggal}&jam=${jam}&durasi=${durasi}&schedule_id=${scheduleId}`)
            .then(response => response.json())
            .then(data => {
                if (data.available) {
                    showAvailabilityResult('success', 'Pekerja tersedia pada waktu yang dipilih.');
                } else {
                    let message = '<strong class="worker-unavailable">Pekerja tidak tersedia!</strong><br><br>';
                    message += '<strong>Jadwal yang bentrok:</strong><ul>';
                    
                    if (data.conflicts && data.conflicts.length > 0) {
                        data.conflicts.forEach(conflict => {
                            const endTime = conflict.durasi_estimasi ? 
                                new Date(`2000-01-01T${conflict.jam}`).addMinutes(conflict.durasi_estimasi).toTimeString().substr(0,5) :
                                new Date(`2000-01-01T${conflict.jam}`).addHours(2).toTimeString().substr(0,5);
                            
                            message += `<li><strong>${conflict.kode_jadwal}</strong>: ${conflict.tanggal} ${conflict.jam}-${endTime} (${conflict.nama_perusahaan})</li>`;
                        });
                    }
                    message += '</ul>';
                    message += '<small class="text-muted">Silakan pilih waktu lain atau pekerja lain.</small>';
                    
                    showAvailabilityResult('error', message);
                }
            })
            .catch(error => {
                console.error('Error checking availability:', error);
                showAvailabilityResult('error', 'Gagal mengecek ketersediaan pekerja. Silakan coba lagi.');
            });
    }

    function showAvailabilityResult(type, message) {
        availabilityResult.innerHTML = `
            <div class="alert alert-${type === 'success' ? 'success' : 'danger'} mb-0">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
            </div>
        `;
    }

    function clearAvailabilityResult() {
        availabilityResult.innerHTML = '';
        availabilityMessage.textContent = 'Pilih pekerja, tanggal, dan jam untuk mengecek ketersediaan';
    }

    // Helper function untuk menambah menit ke Date
    Date.prototype.addMinutes = function(minutes) {
        this.setMinutes(this.getMinutes() + minutes);
        return this;
    };

    Date.prototype.addHours = function(hours) {
        this.setHours(this.getHours() + hours);
        return this;
    };

    // Validasi form sebelum submit
    form.addEventListener('submit', function(event) {
        const pekerjaId = pekerjaSelect.value;
        const tanggal = tanggalInput.value;
        const jam = jamInput.value;
        const durasi = durasiEstimasiInput.value || 120;
        const scheduleId = formScheduleId.value;
        
        // Validasi tanggal tidak di masa lalu
        const selectedDate = new Date(tanggal);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (selectedDate < today) {
            alert('Tanggal tidak boleh di masa lalu!');
            event.preventDefault();
            return;
        }
        
        // Validasi required fields
        if (!customerSelect.value) {
            alert('Harap pilih customer!');
            event.preventDefault();
            return;
        }
        
        if (!tanggal) {
            alert('Harap pilih tanggal!');
            event.preventDefault();
            return;
        }
        
        if (!jam) {
            alert('Harap pilih jam!');
            event.preventDefault();
            return;
        }
        
        // Validasi untuk jadwal berulang
        const jenisPeriode = jenisPeriodeSelect.value;
        const jumlahKunjungan = parseInt(jumlahKunjunganInput.value);
        
        if (jenisPeriode !== 'Sekali') {
            if (jumlahKunjungan < 2) {
                alert('Jumlah kunjungan minimal 2 untuk jadwal berulang!');
                event.preventDefault();
                return;
            }
            
            if (jumlahKunjungan > 100) {
                alert('Jumlah kunjungan maksimal 100!');
                event.preventDefault();
                return;
            }
            
            if (!confirm(`Anda akan membuat ${jumlahKunjungan} jadwal ${jenisPeriode.toLowerCase()}. Lanjutkan?`)) {
                event.preventDefault();
                return;
            }
        }
        
        // Validasi availability pekerja (hanya jika pekerja dipilih)
        if (pekerjaId) {
            // Tampilkan loading
            // submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengecek ketersediaan...';
            // submitButton.disabled = true;
            
            // Cek availability via AJAX
            fetch(`ajax_check_worker_availability.php?worker_id=${pekerjaId}&tanggal=${tanggal}&jam=${jam}&durasi=${durasi}&schedule_id=${scheduleId}`)
                .then(response => response.json())
                .then(data => {
                    submitButton.innerHTML = formAction.value === 'create' ? 'Simpan Jadwal' : 'Update Jadwal';
                    submitButton.disabled = false;
                    
                    if (!data.available) {
                        let errorMessage = 'Pekerja sudah memiliki jadwal yang bentrok:\n\n';
                        
                        if (data.conflicts && data.conflicts.length > 0) {
                            data.conflicts.forEach(conflict => {
                                const endTime = conflict.durasi_estimasi ? 
                                    new Date(`2000-01-01T${conflict.jam}`).addMinutes(conflict.durasi_estimasi).toTimeString().substr(0,5) :
                                    new Date(`2000-01-01T${conflict.jam}`).addHours(2).toTimeString().substr(0,5);
                                
                                errorMessage += `• ${conflict.kode_jadwal}: ${conflict.tanggal} ${conflict.jam}-${endTime} (${conflict.nama_perusahaan})\n`;
                            });
                        }
                        
                        errorMessage += '\nSilakan pilih pekerja lain atau waktu yang berbeda.';
                        alert(errorMessage);
                        event.preventDefault();
                    } else {
                        // Jika available, lanjutkan submit
                        form.submit();
                    }
                })
                .catch(error => {
                    console.error('Error checking availability:', error);
                    submitButton.innerHTML = formAction.value === 'create' ? 'Simpan Jadwal' : 'Update Jadwal';
                    submitButton.disabled = false;
                    alert('Gagal mengecek ketersediaan pekerja. Silakan coba lagi.');
                    event.preventDefault();
                });
            
            event.preventDefault(); // Prevent default submit, wait for AJAX
        }
    });
});
</script>