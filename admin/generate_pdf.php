<?php
// Memuat file-file yang diperlukan
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../tcpdf/tcpdf.php';

// Memastikan hanya admin yang bisa mengakses
checkLogin('admin');

// Dapatkan koneksi PDO
try {
    $pdo = getConnection();
} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// Ambil semua parameter filter dari request dengan sanitasi
$start_date  = isset($_REQUEST['start_date']) && !empty($_REQUEST['start_date']) ? $_REQUEST['start_date'] : null;
$end_date    = isset($_REQUEST['end_date']) && !empty($_REQUEST['end_date']) ? $_REQUEST['end_date'] : null;
$customer_id = isset($_REQUEST['customer_id']) && $_REQUEST['customer_id'] !== '' ? (int)$_REQUEST['customer_id'] : null;
$service_id  = isset($_REQUEST['service_id']) && $_REQUEST['service_id'] !== '' ? (int)$_REQUEST['service_id'] : null;
$worker_id   = isset($_REQUEST['worker_id']) && $_REQUEST['worker_id'] !== '' ? (int)$_REQUEST['worker_id'] : null;
$action      = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'pdf';
$report_type = isset($_REQUEST['report_type']) ? $_REQUEST['report_type'] : 'all';
$show_photos = isset($_REQUEST['show_photos']) && $_REQUEST['show_photos'] == '1' ? true : false;

// Validasi input
if ($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = null;
}
if ($end_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = null;
}

// --- Logika Pengambilan Data untuk Pest Control ---
$sql = "SELECT 
        r.id as report_id,
        r.kode_laporan,
        r.tanggal_pelaporan,
        r.jam_mulai,
        r.jam_selesai,
        r.keterangan,
        r.bahan_digunakan,
        r.hasil_pengamatan,
        r.rekomendasi,
        r.foto_bukti,
        r.foto_sebelum,
        r.foto_sesudah,
        r.rating_customer,
        r.created_at,
        u.id as user_id,
        u.nama as pekerja_nama, 
        u.username as pekerja_username, 
        u.jabatan as pekerja_jabatan,
        c.id as customer_id,
        c.nama_perusahaan, 
        c.nama_customer, 
        c.telepon as customer_telepon, 
        c.alamat as customer_alamat,
        j.id as jadwal_id,
        j.tanggal as jadwal_tanggal, 
        j.jam as jadwal_jam, 
        j.lokasi as jadwal_lokasi, 
        j.prioritas,
        j.status as jadwal_status,
        s.id as service_id,
        s.nama_service, 
        s.kode_service, 
        s.harga as service_harga, 
        s.deskripsi as service_deskripsi,
        a.nama as admin_nama
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN jadwal j ON r.jadwal_id = j.id
        LEFT JOIN services s ON j.service_id = s.id
        LEFT JOIN admin_users a ON j.admin_id = a.id
        WHERE 1=1";

$whereClause = [];
$params = [];

if ($start_date)  { 
    $whereClause[] = "DATE(r.tanggal_pelaporan) >= ?"; 
    $params[] = $start_date; 
}
if ($end_date)    { 
    $whereClause[] = "DATE(r.tanggal_pelaporan) <= ?"; 
    $params[] = $end_date; 
}
if ($customer_id) { 
    $whereClause[] = "c.id = ?"; 
    $params[] = $customer_id; 
}
if ($service_id)  { 
    $whereClause[] = "s.id = ?"; 
    $params[] = $service_id; 
}
if ($worker_id)   { 
    $whereClause[] = "u.id = ?"; 
    $params[] = $worker_id; 
}

// Filter berdasarkan tipe laporan
if ($report_type === 'with_photo') {
    $whereClause[] = "(r.foto_bukti IS NOT NULL OR r.foto_sebelum IS NOT NULL OR r.foto_sesudah IS NOT NULL)";
} elseif ($report_type === 'without_photo') {
    $whereClause[] = "(r.foto_bukti IS NULL AND r.foto_sebelum IS NULL AND r.foto_sesudah IS NULL)";
} elseif ($report_type === 'today_only') {
    $whereClause[] = "DATE(r.tanggal_pelaporan) = CURDATE()";
} elseif ($report_type === 'by_schedule') {
    $whereClause[] = "r.jadwal_id IS NOT NULL";
} elseif ($report_type === 'high_priority') {
    $whereClause[] = "j.prioritas IN ('Tinggi', 'Darurat')";
}

if (!empty($whereClause)) {
    $sql .= " AND " . implode(" AND ", $whereClause);
}

$sql .= " ORDER BY r.tanggal_pelaporan DESC, r.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error mengambil data laporan: " . $e->getMessage());
}

// Kelompokkan berdasarkan layanan (services)
$reportsByService = [];
foreach ($reports as $report) {
    $serviceId = $report['service_id'] ?? 'no_service';
    $serviceName = $report['nama_service'] ?? 'Tanpa Layanan';
    if (!isset($reportsByService[$serviceId])) {
        $reportsByService[$serviceId] = [
            'service_name' => $serviceName,
            'kode_service' => $report['kode_service'] ?? '-',
            'service_harga' => (float)($report['service_harga'] ?? 0),
            'reports' => []
        ];
    }
    $reportsByService[$serviceId]['reports'][] = $report;
}

// Hitung statistik
$total_reports = count($reports);
$total_revenue = 0;
$report_counts = [
    'with_photo' => 0,
    'high_priority' => 0
];

foreach ($reports as $report) {
    $service_price = (float)($report['service_harga'] ?? 0);
    $total_revenue += $service_price;
    
    // Hitung laporan dengan foto
    if (!empty($report['foto_bukti']) || !empty($report['foto_sebelum']) || !empty($report['foto_sesudah'])) {
        $report_counts['with_photo']++;
    }
    
    // Hitung laporan prioritas tinggi
    if (in_array($report['prioritas'] ?? '', ['Tinggi', 'Darurat'])) {
        $report_counts['high_priority']++;
    }
}

// ===================================================================
// --- FUNGSI UNTUK MENAMPILKAN FOTO ---
// ===================================================================
function addPhotoToPDF($pdf, $photoPath, $caption, $x, $y) {
    $maxWidth = 80; // mm
    $maxHeight = 40; // mm
    
    // Simpan posisi Y awal
    $startY = $y;
    
    // Gambar border untuk foto
    $pdf->SetDrawColor(150, 150, 150);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($x, $y, $maxWidth, $maxHeight + 5);
    
    // Coba tambahkan foto
    try {
        if (file_exists($photoPath)) {
            // Dapatkan dimensi gambar
            $imageInfo = getimagesize($photoPath);
            if ($imageInfo !== false) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
                
                // Hitung rasio untuk fitting
                $widthRatio = $maxWidth / $width;
                $heightRatio = ($maxHeight - 5) / $height;
                $ratio = min($widthRatio, $heightRatio);
                
                $newWidth = $width * $ratio;
                $newHeight = $height * $ratio;
                
                // Hitung posisi tengah
                $xPos = $x + ($maxWidth - $newWidth) / 2;
                $yPos = $y + 2;
                
                // Tambahkan gambar
                $pdf->Image($photoPath, $xPos, $yPos, $newWidth, $newHeight, '', '', '', false, 300, '', false, false, 0, false, false, false);
            } else {
                // Jika tidak bisa mendapatkan info gambar
                $pdf->SetFont('helvetica', 'I', 8);
                $pdf->SetTextColor(150, 150, 150);
                $pdf->SetXY($x, $y + ($maxHeight / 2) - 5);
                $pdf->Cell($maxWidth, 10, 'Gambar tidak\nterbaca', 0, 0, 'C');
                $pdf->SetTextColor(0, 0, 0);
            }
        } else {
            // Jika file tidak ditemukan
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetXY($x, $y + ($maxHeight / 2) - 5);
            $pdf->Cell($maxWidth, 10, 'File tidak\nditemukan', 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    } catch (Exception $e) {
        // Jika error saat menambahkan gambar
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetXY($x, $y + ($maxHeight / 2) - 5);
        $pdf->Cell($maxWidth, 10, 'Error: ' . substr($e->getMessage(), 0, 20), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
    
    // Tambahkan caption
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($x, $y + $maxHeight - 3);
    $pdf->Cell($maxWidth, 5, $caption, 0, 0, 'C');
    
    // Kembalikan posisi Y ke awal
    $pdf->SetY($startY);
}

// ===================================================================
// --- FUNGSI untuk Export Excel ---
// ===================================================================
if ($action === 'excel') {
    // Header untuk download Excel
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment;filename="Laporan_Pest_Control_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Laporan Pest Control</title>
        <style>
            table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #198754; color: white; font-weight: bold; }
            .total-row { background-color: #f8f9fa; font-weight: bold; }
            .service-header { background-color: #e7f5ff; font-weight: bold; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .monospace { font-family: monospace; }
            .small { font-size: 11px; }
            .photo-cell { max-width: 100px; text-align: center; }
            .photo-icon { color: #198754; }
        </style>
    </head>
    <body>';
    
    echo '<table>';
    echo '<tr>';
    echo '<th colspan="14" style="background-color: #198754; color: white; font-size: 18px; padding: 15px; text-align: center;">LAPORAN PEST CONTROL - PT. REXON MITRA PRIMA</th>';
    echo '</tr>';
    echo '<tr>';
    echo '<td colspan="14" style="text-align: center; padding: 10px; background-color: #f8f9fa;">';
    echo '<strong>Periode:</strong> ' . ($start_date ? formatTanggalIndonesia($start_date) : 'Awal') . ' s/d ' . ($end_date ? formatTanggalIndonesia($end_date) : 'Akhir') . ' | ';
    echo '<strong>Total Laporan:</strong> ' . $total_reports . ' | ';
    echo '<strong>Total Pendapatan:</strong> Rp ' . number_format($total_revenue, 0, ',', '.');
    if ($report_counts['with_photo'] > 0) {
        echo ' | <strong>Dengan Foto:</strong> ' . $report_counts['with_photo'];
    }
    if ($report_counts['high_priority'] > 0) {
        echo ' | <strong>Prioritas Tinggi:</strong> ' . $report_counts['high_priority'];
    }
    echo '</td>';
    echo '</tr>';
    
    if (!empty($reportsByService)) {
        foreach ($reportsByService as $serviceId => $serviceData) {
            $service_total = count($serviceData['reports']);
            $service_revenue = $serviceData['service_harga'] * $service_total;
            
            echo '<tr class="service-header">';
            echo '<td colspan="14" style="background-color: #e7f5ff; padding: 10px;">';
            echo '<strong>LAYANAN:</strong> ' . htmlspecialchars($serviceData['service_name']) . ' (' . htmlspecialchars($serviceData['kode_service']) . ') | ';
            echo '<strong>Jumlah:</strong> ' . $service_total . ' pekerjaan | ';
            echo '<strong>Harga Satuan:</strong> Rp ' . number_format($serviceData['service_harga'], 0, ',', '.') . ' | ';
            echo '<strong>Total Pendapatan:</strong> Rp ' . number_format($service_revenue, 0, ',', '.');
            echo '</td>';
            echo '</tr>';
            
            // Header tabel untuk layanan ini
            echo '<tr>';
            echo '<th style="width: 30px;">No</th>';
            echo '<th>Kode Laporan</th>';
            echo '<th>Tanggal Laporan</th>';
            echo '<th>Perusahaan</th>';
            echo '<th>Customer</th>';
            echo '<th>Pekerja</th>';
            echo '<th>Jadwal</th>';
            echo '<th>Lokasi</th>';
            echo '<th>Prioritas</th>';
            echo '<th>Status</th>';
            echo '<th>Keterangan</th>';
            echo '<th>Foto Bukti</th>';
            echo '<th>Foto Sebelum</th>';
            echo '<th>Foto Sesudah</th>';
            echo '</tr>';
            
            $no = 1;
            foreach ($serviceData['reports'] as $report) {
                $keterangan = htmlspecialchars($report['keterangan'] ?? '');
                if (strlen($keterangan) > 80) {
                    $keterangan = substr($keterangan, 0, 80) . '...';
                }
                
                echo '<tr>';
                echo '<td class="text-center">' . $no++ . '</td>';
                echo '<td class="monospace small">' . htmlspecialchars($report['kode_laporan'] ?? 'N/A') . '</td>';
                echo '<td class="small">' . formatTanggalIndonesia($report['tanggal_pelaporan']) . ' ' . ($report['jam_mulai'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($report['nama_perusahaan'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($report['nama_customer'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($report['pekerja_nama'] ?? 'N/A') . '</td>';
                echo '<td class="small">' . ($report['jadwal_tanggal'] ? formatTanggalIndonesia($report['jadwal_tanggal']) . ' ' . ($report['jadwal_jam'] ?? '') : 'N/A') . '</td>';
                echo '<td class="small">' . htmlspecialchars(substr($report['jadwal_lokasi'] ?? 'N/A', 0, 30)) . '</td>';
                echo '<td class="text-center">' . htmlspecialchars($report['prioritas'] ?? 'N/A') . '</td>';
                echo '<td class="text-center">' . htmlspecialchars($report['jadwal_status'] ?? 'N/A') . '</td>';
                echo '<td class="small">' . $keterangan . '</td>';
                
                // Kolom Foto Bukti
                echo '<td class="photo-cell">';
                if (!empty($report['foto_bukti'])) {
                    $photo_path = '../assets/uploads/' . $report['foto_bukti'];
                    echo '<span class="photo-icon">✓ Ada</span>';
                } else {
                    echo '-';
                }
                echo '</td>';
                
                // Kolom Foto Sebelum
                echo '<td class="photo-cell">';
                if (!empty($report['foto_sebelum'])) {
                    $photo_path = '../assets/uploads/' . $report['foto_sebelum'];
                    echo '<span class="photo-icon">✓ Ada</span>';
                } else {
                    echo '-';
                }
                echo '</td>';
                
                // Kolom Foto Sesudah
                echo '<td class="photo-cell">';
                if (!empty($report['foto_sesudah'])) {
                    $photo_path = '../assets/uploads/' . $report['foto_sesudah'];
                    echo '<span class="photo-icon">✓ Ada</span>';
                } else {
                    echo '-';
                }
                echo '</td>';
                
                echo '</tr>';
            }
            
            echo '<tr class="total-row">';
            echo '<td colspan="11" style="text-align: right; background-color: #f8f9fa; padding: 10px;"><strong>Subtotal Layanan ' . htmlspecialchars($serviceData['service_name']) . ':</strong></td>';
            echo '<td colspan="3" style="background-color: #f8f9fa; font-weight: bold; padding: 10px;">Rp ' . number_format($service_revenue, 0, ',', '.') . '</td>';
            echo '</tr>';
            
            // Tambah baris kosong antar layanan
            echo '<tr><td colspan="14" style="height: 5px; background-color: #fff;"></td></tr>';
        }
        
        // Total keseluruhan
        echo '<tr class="total-row">';
        echo '<td colspan="11" style="text-align: right; background-color: #198754; color: white; padding: 12px;"><strong>TOTAL KESELURUHAN:</strong></td>';
        echo '<td colspan="3" style="background-color: #198754; color: white; font-weight: bold; padding: 12px;">Rp ' . number_format($total_revenue, 0, ',', '.') . '</td>';
        echo '</tr>';
        
    } else {
        echo '<tr><td colspan="14" style="text-align: center; padding: 40px; color: #666; font-style: italic;">Tidak ada data laporan yang sesuai dengan filter yang dipilih.</td></tr>';
    }
    
    echo '</table>';
    
    // Footer informasi
    echo '<div style="margin-top: 30px; text-align: center; font-size: 11px; color: #666; padding-top: 20px; border-top: 1px solid #eee;">';
    echo '<strong>PT. REXON MITRA PRIMA</strong><br>';
    echo 'Komplek Perumahan Salaka Nagara Ruko Nomor 3, Balaraja, Tangerang - Banten 15610<br>';
    echo 'Telp: 0812-3456-7890 | Email: info@rexonpestcontrol.com<br>';
    echo '<em>Dokumen ini dihasilkan secara otomatis oleh Sistem Pest Control pada ' . date('d/m/Y H:i:s') . '</em>';
    echo '</div>';
    
    echo '</body></html>';
    exit;
}

// ===================================================================
// --- MULAI PEMBUATAN PDF ---
// ===================================================================

// 1. Inisialisasi dan Konfigurasi Dasar TCPDF
try {
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
} catch (Exception $e) {
    die("Error inisialisasi TCPDF: " . $e->getMessage());
}

$pdf->SetCreator('Sistem Laporan Pest Control');
$pdf->SetAuthor('Admin Pest Control');
$pdf->SetTitle('Laporan Pest Control - ' . date('d-m-Y'));
$pdf->SetSubject('Laporan Pekerjaan Pest Control');
$pdf->setPrintHeader(false); // Menghilangkan header default
$pdf->setPrintFooter(false); // Menghilangkan footer default
$pdf->SetMargins(10, 10, 10); // Margin: kiri, atas, kanan
$pdf->SetAutoPageBreak(true, 15); // Margin bawah untuk auto page break
$pdf->AddPage();

// 2. KOP SURAT PT. REXON MITRA PRIMA
// -----------------------------------------------------------------
$logoPath = '../assets/img/hama.png';
if (file_exists($logoPath)) {
    try {
        $pdf->Image($logoPath, 10, 12, 20, 0, 'PNG');
    } catch (Exception $e) {
        // Logo tidak ditemukan, lanjutkan tanpa logo
    }
}

$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetXY(35, 10);
$pdf->Cell(0, 5, 'PT. REXON MITRA PRIMA', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetXY(35, 16);
$pdf->Cell(0, 5, 'JASA PEMBASMI HAMA PROFESIONAL', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(35, 22);
$pdf->Cell(0, 5, 'Komplek Perumahan Salaka Nagara Ruko Nomor 3, Balaraja, Tangerang - Banten 15610', 0, 1, 'L');
$pdf->SetXY(35, 27);
$pdf->Cell(0, 5, 'Telp: 0812-3456-7890 | Email: info@rexonpestcontrol.com', 0, 1, 'L');
$pdf->Line(10, 35, 287, 35); // Garis pemisah (landscape mode)
$pdf->Ln(8);

// 3. JUDUL LAPORAN DAN INFORMASI FILTER
// -----------------------------------------------------------------
$pdf->SetFont('helvetica', 'BU', 14);
if ($show_photos) {
    $pdf->Cell(0, 8, 'LAPORAN PEKERJAAN PEST CONTROL DENGAN FOTO', 0, 1, 'C');
} else {
    $pdf->Cell(0, 8, 'LAPORAN PEKERJAAN PEST CONTROL', 0, 1, 'C');
}
$pdf->SetFont('helvetica', '', 10);

$filterInfo = "Periode: " . ($start_date ? formatTanggalIndonesia($start_date) : 'Awal') . " s/d " . ($end_date ? formatTanggalIndonesia($end_date) : 'Akhir');
$pdf->Cell(0, 5, $filterInfo, 0, 1, 'C');

$summaryInfo = "Total Laporan: $total_reports | Total Pendapatan: Rp " . number_format($total_revenue, 0, ',', '.');
$pdf->Cell(0, 5, $summaryInfo, 0, 1, 'C');

$additionalInfo = "";
if ($report_counts['with_photo'] > 0) {
    $additionalInfo .= "Laporan dengan Foto: " . $report_counts['with_photo'] . " | ";
}
if ($report_counts['high_priority'] > 0) {
    $additionalInfo .= "Prioritas Tinggi: " . $report_counts['high_priority'];
}
if (!empty($additionalInfo)) {
    $pdf->Cell(0, 5, $additionalInfo, 0, 1, 'C');
}

$pdf->Ln(5);

// ===================================================================
// --- BAGIAN LAPORAN ---
// ===================================================================
if (!empty($reportsByService)) {
    $total_pendapatan = 0;
    $total_laporan = 0;
    $service_count = count($reportsByService);
    
    foreach ($reportsByService as $serviceId => $serviceData) {
        $total_layanan = count($serviceData['reports']);
        $total_laporan += $total_layanan;
        $pendapatan_layanan = $serviceData['service_harga'] * $total_layanan;
        $total_pendapatan += $pendapatan_layanan;
        
        // Header Layanan
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(220, 237, 200); // Hijau muda
        $pdf->Cell(0, 8, 'LAYANAN: ' . htmlspecialchars($serviceData['service_name']) . ' (' . htmlspecialchars($serviceData['kode_service']) . ')', 0, 1, 'L', true);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Jumlah Pekerjaan: ' . $total_layanan . ' | Harga per Layanan: Rp ' . number_format($serviceData['service_harga'], 0, ',', '.') . ' | Total Pendapatan: Rp ' . number_format($pendapatan_layanan, 0, ',', '.'), 0, 1, 'L');
        $pdf->Ln(3);
        
        if ($show_photos) {
            // Mode dengan foto - tampilkan per laporan dengan detail lengkap
            $no = 1;
            foreach ($serviceData['reports'] as $report) {
                // Cek jika perlu halaman baru
                if ($pdf->GetY() > ($pdf->getPageHeight() - 100)) {
                    $pdf->AddPage();
                }
                
                // Header Laporan
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->SetFillColor(240, 240, 240);
                $pdf->Cell(0, 7, 'Laporan #' . $no++ . ' - ' . htmlspecialchars($report['kode_laporan'] ?? 'N/A'), 0, 1, 'L', true);
                $pdf->SetFont('helvetica', '', 9);
                
                // Informasi Laporan
                $infoY = $pdf->GetY();
                
                // Kolom kiri - Informasi dasar
                $pdf->SetXY(15, $infoY);
                $pdf->MultiCell(80, 5, 
                    "Tanggal Laporan: " . formatTanggalIndonesia($report['tanggal_pelaporan']) . "\n" .
                    "Jam: " . ($report['jam_mulai'] ?? '-') . " - " . ($report['jam_selesai'] ?? '-') . "\n" .
                    "Perusahaan: " . htmlspecialchars($report['nama_perusahaan'] ?? 'N/A') . "\n" .
                    "Customer: " . htmlspecialchars($report['nama_customer'] ?? 'N/A') . "\n" .
                    "Pekerja: " . htmlspecialchars($report['pekerja_nama'] ?? 'N/A') . "\n" .
                    "Jabatan: " . htmlspecialchars($report['pekerja_jabatan'] ?? 'N/A')
                , 0, 'L');
                
                // Kolom kanan - Informasi tambahan
                $pdf->SetXY(105, $infoY);
                $pdf->MultiCell(80, 5, 
                    "Jadwal: " . ($report['jadwal_tanggal'] ? formatTanggalIndonesia($report['jadwal_tanggal']) : 'N/A') . "\n" .
                    "Jam Jadwal: " . ($report['jadwal_jam'] ?? '-') . "\n" .
                    "Lokasi: " . htmlspecialchars($report['jadwal_lokasi'] ?? 'N/A') . "\n" .
                    "Prioritas: " . htmlspecialchars($report['prioritas'] ?? 'N/A') . "\n" .
                    "Status: " . htmlspecialchars($report['jadwal_status'] ?? 'N/A') . "\n" .
                    "Rating: " . ($report['rating_customer'] ? str_repeat('★', $report['rating_customer']) . ' (' . $report['rating_customer'] . '/5)' : '-')
                , 0, 'L');
                
                $pdf->Ln(2);
                
                // Deskripsi/Keterangan
                if (!empty($report['keterangan'])) {
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->Cell(0, 5, 'Keterangan Pekerjaan:', 0, 1, 'L');
                    $pdf->SetFont('helvetica', '', 8);
                    $keterangan = htmlspecialchars($report['keterangan']);
                    if (strlen($keterangan) > 500) {
                        $keterangan = substr($keterangan, 0, 500) . '...';
                    }
                    $pdf->MultiCell(0, 4, $keterangan, 0, 'L');
                    $pdf->Ln(2);
                }
                
                // ===================================================================
                // --- BAGIAN FOTO ---
                // ===================================================================
                $hasPhotos = false;
                $photoY = $pdf->GetY();
                
                // Foto Bukti
                if (!empty($report['foto_bukti'])) {
                    $hasPhotos = true;
                    $photoPath = '../assets/uploads/' . $report['foto_bukti'];
                    addPhotoToPDF($pdf, $photoPath, "Foto Bukti", 15, $photoY);
                }
                
                // Foto Sebelum
                if (!empty($report['foto_sebelum'])) {
                    $hasPhotos = true;
                    $photoPath = '../assets/uploads/' . $report['foto_sebelum'];
                    $xPos = 15 + 90; // Posisi kedua (90mm dari kiri)
                    addPhotoToPDF($pdf, $photoPath, "Foto Sebelum", $xPos, $photoY);
                }
                
                // Foto Sesudah
                if (!empty($report['foto_sesudah'])) {
                    $hasPhotos = true;
                    $photoPath = '../assets/uploads/' . $report['foto_sesudah'];
                    $xPos = 15 + 180; // Posisi ketiga (180mm dari kiri)
                    addPhotoToPDF($pdf, $photoPath, "Foto Sesudah", $xPos, $photoY);
                }
                
                if ($hasPhotos) {
                    $pdf->SetY($photoY + 45); // Pindah ke bawah setelah foto
                }
                
                // Garis pemisah antar laporan
                $pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
                $pdf->Ln(5);
                
                // Jika sudah di akhir halaman, tambah halaman baru
                if ($pdf->GetY() > ($pdf->getPageHeight() - 30)) {
                    $pdf->AddPage();
                }
            }
        } else {
            // Mode tanpa foto - tampilkan tabel ringkas
            // Header Tabel
            $header = ['No', 'Kode Laporan', 'Tanggal Laporan', 'Perusahaan', 'Customer', 'Pekerja', 'Jadwal', 'Lokasi', 'Prioritas'];
            $widths = [8, 20, 22, 35, 25, 25, 22, 35, 15];
            
            $pdf->SetFillColor(240, 240, 240);
            $pdf->SetTextColor(0);
            $pdf->SetDrawColor(180, 180, 180);
            $pdf->SetFont('helvetica', 'B', 7);
            
            // Draw header
            for ($i = 0; $i < count($header); $i++) {
                $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', 1);
            }
            $pdf->Ln();
            
            // Data Laporan
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetFillColor(255);
            $no = 1;
            $rowHeight = 8;
            
            foreach ($serviceData['reports'] as $report) {
                // Cek apakah perlu page break
                if ($pdf->GetY() > ($pdf->getPageHeight() - 30)) {
                    $pdf->AddPage();
                    // Tambah header tabel di halaman baru
                    $pdf->SetFillColor(240, 240, 240);
                    $pdf->SetTextColor(0);
                    $pdf->SetDrawColor(180, 180, 180);
                    $pdf->SetFont('helvetica', 'B', 7);
                    for ($i = 0; $i < count($header); $i++) {
                        $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', 1);
                    }
                    $pdf->Ln();
                    $pdf->SetFont('helvetica', '', 7);
                    $pdf->SetFillColor(255);
                }
                
                // Row data
                $currentX = $pdf->GetX();
                $currentY = $pdf->GetY();
                
                // No
                $pdf->MultiCell($widths[0], $rowHeight, $no++, 'LTRB', 'C', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                // Kode Laporan
                $pdf->MultiCell($widths[1], $rowHeight, htmlspecialchars($report['kode_laporan'] ?? 'N/A'), 'LTRB', 'C', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                // Tanggal Laporan
                $tglLaporan = formatTanggalIndonesia($report['tanggal_pelaporan']) . "\n" . ($report['jam_mulai'] ?? '');
                $pdf->MultiCell($widths[2], $rowHeight, $tglLaporan, 'LTRB', 'C', true, 0, '', '', true, 0, true, true, $rowHeight, 'M');
                
                // Perusahaan
                $perusahaan = htmlspecialchars($report['nama_perusahaan'] ?? 'N/A');
                if (strlen($perusahaan) > 20) {
                    $perusahaan = substr($perusahaan, 0, 20) . '...';
                }
                $pdf->MultiCell($widths[3], $rowHeight, $perusahaan, 'LTRB', 'L', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                // Customer
                $customer = htmlspecialchars($report['nama_customer'] ?? 'N/A');
                if (strlen($customer) > 15) {
                    $customer = substr($customer, 0, 15) . '...';
                }
                $pdf->MultiCell($widths[4], $rowHeight, $customer, 'LTRB', 'L', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                // Pekerja
                $pekerja = htmlspecialchars($report['pekerja_nama'] ?? 'N/A');
                if (strlen($pekerja) > 15) {
                    $pekerja = substr($pekerja, 0, 15) . '...';
                }
                $pdf->MultiCell($widths[5], $rowHeight, $pekerja, 'LTRB', 'L', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                // Jadwal
                $jadwal = $report['jadwal_tanggal'] ? formatTanggalIndonesia($report['jadwal_tanggal']) . "\n" . ($report['jadwal_jam'] ?? '') : 'N/A';
                $pdf->MultiCell($widths[6], $rowHeight, $jadwal, 'LTRB', 'C', true, 0, '', '', true, 0, true, true, $rowHeight, 'M');
                
                // Lokasi
                $lokasi = htmlspecialchars($report['jadwal_lokasi'] ?? 'N/A');
                if (strlen($lokasi) > 25) {
                    $lokasi = substr($lokasi, 0, 25) . '...';
                }
                $pdf->MultiCell($widths[7], $rowHeight, $lokasi, 'LTRB', 'L', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                // Prioritas
                $prioritas = $report['prioritas'] ?? 'N/A';
                $fillColor = 255; // default white
                $textColor = 0; // default black
                
                switch(strtolower($prioritas)) {
                    case 'darurat':
                        $fillColor = array(255, 200, 200); // merah muda
                        $textColor = 0; // hitam
                        break;
                    case 'tinggi':
                        $fillColor = array(255, 235, 200); // orange muda
                        $textColor = 0; // hitam
                        break;
                    case 'sedang':
                        $fillColor = array(255, 255, 200); // kuning muda
                        $textColor = 0; // hitam
                        break;
                    case 'rendah':
                        $fillColor = array(200, 255, 200); // hijau muda
                        $textColor = 0; // hitam
                        break;
                    default:
                        $fillColor = 255;
                        $textColor = 0;
                }
                
                $pdf->SetFillColorArray($fillColor);
                $pdf->SetTextColor($textColor);
                $pdf->MultiCell($widths[8], $rowHeight, $prioritas, 'LTRB', 'C', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                // Reset warna untuk row berikutnya
                $pdf->SetFillColor(255);
                $pdf->SetTextColor(0);
                
                $pdf->Ln($rowHeight);
            }
        }
        
        $pdf->Ln(5);
        
        // Total per layanan
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Cell(0, 8, 'Total Layanan ' . htmlspecialchars($serviceData['service_name']) . ': ' . $total_layanan . ' pekerjaan | Total Pendapatan: Rp ' . number_format($pendapatan_layanan, 0, ',', '.'), 0, 1, 'R', true);
        $pdf->Ln(3);
    }
    
    // Ringkasan Total
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(200, 220, 255); // Biru muda
    $pdf->Cell(0, 10, 'RINGKASAN TOTAL', 0, 1, 'C', true);
    $pdf->SetFont('helvetica', '', 10);
    
    $summary = "Total Laporan: " . $total_laporan . " laporan\n" .
               "Total Layanan Berbeda: " . $service_count . " jenis layanan\n" .
               "Total Pendapatan: Rp " . number_format($total_pendapatan, 0, ',', '.') . "\n";
    
    if ($show_photos) {
        $summary .= "Laporan dengan Foto: " . $report_counts['with_photo'] . "\n";
    }
    
    $summary .= "Rata-rata per Laporan: Rp " . ($total_laporan > 0 ? number_format($total_pendapatan / $total_laporan, 0, ',', '.') : '0');
    
    $pdf->MultiCell(0, 8, $summary, 0, 'C', false);
    $pdf->Ln(10);
    
} else {
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 20, 'Tidak ada data laporan yang sesuai dengan filter yang dipilih.', 0, 1, 'C');
}

// 4. BAGIAN TANDA TANGAN
// -----------------------------------------------------------------
if ($pdf->GetY() > ($pdf->getPageHeight() - 50)) {
    $pdf->AddPage();
}

$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 11);
$pdf->SetX(200);
$pdf->Cell(0, 5, 'Tangerang, ' . formatTanggalIndonesia(date('Y-m-d')), 0, 1, 'L');
$pdf->SetX(200);
$pdf->Cell(0, 5, 'Mengetahui,', 0, 1, 'L');
$pdf->SetX(200);
$pdf->Cell(0, 5, 'Manager Operasional', 0, 1, 'L');
$pdf->Ln(15); 
$pdf->SetX(200);
$pdf->SetFont('helvetica', 'BU', 11);
$pdf->Cell(0, 5, 'Saepul', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 11);
$pdf->SetX(200);
$pdf->Cell(0, 5, 'NIK: 1234567890', 0, 1, 'L');

// 5. Footer Hak Cipta
$pdf->SetY(-15);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 10, 'Dokumen ini dihasilkan secara otomatis oleh Sistem Pest Control PT. Rexon Mitra Prima pada ' . date('d/m/Y H:i:s'), 0, 0, 'C');

// 6. Output PDF
// -----------------------------------------------------------------
try {
    if ($show_photos) {
        $filename = 'Laporan_Pest_Control_Foto_' . date('Ymd_His') . '.pdf';
    } else {
        $filename = 'Laporan_Pest_Control_' . date('Ymd_His') . '.pdf';
    }
    $pdf->Output($filename, 'I'); // 'I' untuk tampil di browser, 'D' untuk download
} catch (Exception $e) {
    die("Error output PDF: " . $e->getMessage());
}

exit;
?>