<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pekerja_id = (int)$_GET['pekerja_id'];
$tanggal = $_GET['tanggal'];
$jam = $_GET['jam'];
$durasi = (int)$_GET['durasi'];
$schedule_id = !empty($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : null;

if (!$pekerja_id || !$tanggal || !$jam) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Hitung jam selesai
    $jam_mulai_dt = new DateTime($tanggal . ' ' . $jam);
    $jam_selesai_dt = clone $jam_mulai_dt;
    $jam_selesai_dt->modify("+{$durasi} minutes");
    $jam_selesai = $jam_selesai_dt->format('H:i:s');
    
    // Query untuk mencari jadwal yang bentrok - SEMUA KEMUNGKINAN BENTROK
    $query = "SELECT 
                j.id,
                j.kode_jadwal,
                j.tanggal,
                j.jam as jam_mulai,
                ADDTIME(j.jam, SEC_TO_TIME(COALESCE(j.durasi_estimasi, s.durasi_menit, 60) * 60)) as jam_selesai,
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
                AND (
                    -- Case 1: Jadwal baru dimulai di tengah jadwal yang sudah ada
                    (TIME(?) >= TIME(j.jam) AND TIME(?) < ADDTIME(j.jam, SEC_TO_TIME(COALESCE(j.durasi_estimasi, s.durasi_menit, 60) * 60)))
                    OR
                    -- Case 2: Jadwal baru selesai di tengah jadwal yang sudah ada
                    (TIME(?) > TIME(j.jam) AND TIME(?) <= ADDTIME(j.jam, SEC_TO_TIME(COALESCE(j.durasi_estimasi, s.durasi_menit, 60) * 60)))
                    OR
                    -- Case 3: Jadwal yang sudah ada dimulai di tengah jadwal baru
                    (TIME(j.jam) >= TIME(?) AND TIME(j.jam) < TIME(?))
                    OR
                    -- Case 4: Jadwal baru mencakup seluruh jadwal yang sudah ada
                    (TIME(?) <= TIME(j.jam) AND TIME(?) >= ADDTIME(j.jam, SEC_TO_TIME(COALESCE(j.durasi_estimati, s.durasi_menit, 60) * 60)))
                    OR
                    -- Case 5: Jadwal yang sama persis
                    (TIME(?) = TIME(j.jam))
                )";
    
    $params = [
        $pekerja_id, $tanggal, 
        $jam, $jam, 
        $jam_selesai, $jam_selesai,
        $jam, $jam_selesai,
        $jam, $jam_selesai,
        $jam
    ];
    
    if ($schedule_id) {
        $query .= " AND j.id != ?";
        $params[] = $schedule_id;
    }
    
    $query .= " ORDER BY j.jam ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format hasil
    $formatted_conflicts = [];
    foreach ($conflicts as $conflict) {
        $jam_mulai_conflict = date('H:i', strtotime($conflict['jam_mulai']));
        $jam_selesai_conflict = date('H:i', strtotime($conflict['jam_selesai']));
        $formatted_conflicts[] = [
            'kode_jadwal' => $conflict['kode_jadwal'],
            'customer' => $conflict['customer'],
            'jam_mulai' => $jam_mulai_conflict,
            'jam_selesai' => $jam_selesai_conflict,
            'durasi' => $conflict['durasi'],
            'status' => $conflict['status']
        ];
    }
    
    echo json_encode([
        'has_conflict' => count($formatted_conflicts) > 0,
        'conflicts' => $formatted_conflicts,
        'conflict_count' => count($formatted_conflicts),
        'message' => count($formatted_conflicts) > 0 ? 'Jadwal bentrok ditemukan' : 'Tidak ada bentrok'
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>