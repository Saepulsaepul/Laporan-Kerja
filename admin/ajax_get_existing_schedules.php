<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pekerja_id = (int)$_GET['pekerja_id'];
$tanggal = $_GET['tanggal'];

if (!$pekerja_id || !$tanggal) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Ambil semua jadwal pekerja pada tanggal tersebut
    $stmt = $pdo->prepare("
        SELECT 
            j.id,
            j.kode_jadwal,
            j.jam as jam_mulai,
            ADDTIME(
                j.jam, 
                SEC_TO_TIME(COALESCE(j.durasi_estimasi, s.durasi_menit, 60) * 60)
            ) as jam_selesai,
            COALESCE(j.durasi_estimasi, s.durasi_menit, 60) as durasi,
            c.nama_perusahaan,
            c.nama_customer,
            CONCAT(c.nama_perusahaan, ' - ', c.nama_customer) as customer,
            j.status
        FROM jadwal j
        LEFT JOIN services s ON j.service_id = s.id
        LEFT JOIN customers c ON j.customer_id = c.id
        WHERE j.pekerja_id = ?
            AND j.tanggal = ?
            AND j.status NOT IN ('Selesai', 'Dibatalkan')
        ORDER BY j.jam ASC
    ");
    $stmt->execute([$pekerja_id, $tanggal]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format hasil
    $formatted_schedules = [];
    foreach ($schedules as $schedule) {
        $jam_mulai = date('H:i', strtotime($schedule['jam_mulai']));
        $jam_selesai = date('H:i', strtotime($schedule['jam_selesai']));
        $formatted_schedules[] = [
            'kode_jadwal' => $schedule['kode_jadwal'],
            'customer' => $schedule['customer'],
            'jam_mulai' => $jam_mulai,
            'jam_selesai' => $jam_selesai,
            'durasi' => $schedule['durasi'],
            'status' => $schedule['status']
        ];
    }
    
    echo json_encode([
        'schedules' => $formatted_schedules,
        'total_schedules' => count($formatted_schedules)
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>