<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['customer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Customer ID required']);
    exit;
}

$customer_id = (int)$_GET['customer_id'];

try {
    $pdo = getConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.kode_service,
            s.nama_service,
            s.harga,
            s.durasi_menit,
            s.kategori,
            cs.tanggal_mulai,
            cs.tanggal_selesai,
            cs.status as service_status
        FROM customer_services cs
        JOIN services s ON cs.service_id = s.id
        WHERE cs.customer_id = ?
        AND cs.status = 'Aktif'
        ORDER BY s.nama_service ASC
    ");
    
    $stmt->execute([$customer_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'services' => $services
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}