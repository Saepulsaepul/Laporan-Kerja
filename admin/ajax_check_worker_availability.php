<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();

$worker_id = $_GET['worker_id'] ?? 0;
$tanggal = $_GET['tanggal'] ?? '';
$jam = $_GET['jam'] ?? '';
$durasi = $_GET['durasi'] ?? 120;
$schedule_id = $_GET['schedule_id'] ?? 0;

if (!$worker_id || !$tanggal || !$jam) {
    echo json_encode(['available' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Query untuk cek bentrok
    $query = "
        SELECT j.id, j.kode_jadwal, j.tanggal, j.jam, 
               j.durasi_estimasi, j.customer_id, c.nama_perusahaan,
               ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '02:00:00')) as jam_selesai
        FROM jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        WHERE j.pekerja_id = ?
        AND j.status NOT IN ('Selesai', 'Dibatalkan')
        AND j.tanggal = ?
        AND (
            (? BETWEEN j.jam AND ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '02:00:00')))
            OR
            (ADDTIME(?, COALESCE(CONCAT('00:', LPAD(?, 2, '0'), ':00'), '02:00:00')) BETWEEN j.jam AND ADDTIME(j.jam, COALESCE(CONCAT('00:', LPAD(j.durasi_estimasi, 2, '0'), ':00'), '02:00:00')))
            OR
            (j.jam BETWEEN ? AND ADDTIME(?, COALESCE(CONCAT('00:', LPAD(?, 2, '0'), ':00'), '02:00:00')))
        )
    ";
    
    $params = [
        $worker_id,
        $tanggal,
        $jam,
        $jam,
        $durasi,
        $jam,
        $jam,
        $durasi
    ];
    
    // Jika sedang edit, exclude jadwal yang sedang diedit
    if ($schedule_id) {
        $query .= " AND j.id != ?";
        $params[] = $schedule_id;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($conflicts)) {
        echo json_encode(['available' => true, 'message' => 'Worker is available']);
    } else {
        echo json_encode(['available' => false, 'message' => 'Worker has scheduling conflicts', 'conflicts' => $conflicts]);
    }
    
} catch (PDOException $e) {
    echo json_encode(['available' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}