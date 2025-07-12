<?php
// Memuat file-file yang diperlukan
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../tcpdf/tcpdf.php'; // <-- PASTIKAN PATH INI BENAR

// Memastikan hanya admin yang bisa mengakses
checkLogin('admin');

// Dapatkan koneksi PDO
$pdo = getConnection();

// Ambil semua parameter filter dari request
$start_date  = $_REQUEST['start_date'] ?? null;
$end_date    = $_REQUEST['end_date'] ?? null;
$category_id = isset($_REQUEST['category_id']) && $_REQUEST['category_id'] !== '' ? (int)$_REQUEST['category_id'] : null;
$user_id     = isset($_REQUEST['user_id']) && $_REQUEST['user_id'] !== '' ? (int)$_REQUEST['user_id'] : null;
$section_id  = isset($_REQUEST['section_id']) && $_REQUEST['section_id'] !== '' ? (int)$_REQUEST['section_id'] : null;

// --- Logika Pengambilan Data (TIDAK DIUBAH) ---
$sql = "SELECT r.*, u.nama_lengkap, u.jabatan, c.category_name, s.section_name
        FROM reports r 
        JOIN users u ON r.user_id = u.id 
        JOIN categories c ON r.category_id = c.id
        LEFT JOIN sections s ON c.section_id = s.id";
$whereClause = [];
$params = [];
if ($start_date)  { $whereClause[] = "r.tanggal_pelaporan >= ?"; $params[] = $start_date; }
if ($end_date)    { $whereClause[] = "r.tanggal_pelaporan <= ?"; $params[] = $end_date; }
if ($category_id) { $whereClause[] = "r.category_id = ?";       $params[] = $category_id; }
if ($user_id)     { $whereClause[] = "r.user_id = ?";           $params[] = $user_id; }
if ($section_id)  { $whereClause[] = "c.section_id = ?";        $params[] = $section_id; }
if (!empty($whereClause)) {
    $sql .= " WHERE " . implode(" AND ", $whereClause);
}
$sql .= " ORDER BY s.id ASC, r.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reportsBySection = [];
foreach ($reports as $report) {
    $sectionId = $report['section_id'] ?? 'no_section';
    $sectionName = $report['section_name'] ?? 'Tanpa Seksi';
    if (!isset($reportsBySection[$sectionId])) {
        $reportsBySection[$sectionId] = ['section_name' => $sectionName, 'reports' => []];
    }
    $reportsBySection[$sectionId]['reports'][] = $report;
}
// --- Akhir Logika Pengambilan Data ---


// ===================================================================
// --- MULAI PEMBUATAN PDF ---
// ===================================================================

// 1. Inisialisasi dan Konfigurasi Dasar TCPDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Sistem Laporan Dishub TPI');
$pdf->SetAuthor(htmlspecialchars($_SESSION['admin_nama']));
$pdf->SetTitle('Laporan Resmi Dishub - ' . date('d-m-Y'));
$pdf->SetSubject('Laporan Kegiatan');
$pdf->setPrintHeader(false); // Menghilangkan header default
$pdf->setPrintFooter(false); // Menghilangkan footer default
$pdf->SetMargins(15, 10, 15); // Margin: kiri, atas, kanan
$pdf->SetAutoPageBreak(true, 20); // Margin bawah untuk auto page break
$pdf->AddPage();

// 2. KEPALA SURAT RESMI (KOP SURAT)
// -----------------------------------------------------------------
// GANTI PATH LOGO INI DENGAN LOKASI FILE LOGO ANDA
$logoPath = '../assets/img/logo.png';
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 15, 12, 22, 0, 'PNG');
}
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(40, 15);
$pdf->Cell(0, 5, 'PEMERINTAH KOTA TANJUNGPINANG', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(40, 21);
$pdf->Cell(0, 5, 'DINAS PERHUBUNGAN', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(40, 27);
$pdf->Cell(0, 5, 'Jalan Raya Senggarang No. 1 - Komplek Perkantoran Pemerintah Kota Tanjungpinang', 0, 1, 'L');
$pdf->Line(15, 35, 195, 35); // Garis pemisah
$pdf->Ln(5);

// 3. JUDUL LAPORAN DAN INFORMASI FILTER
// -----------------------------------------------------------------
$pdf->SetFont('helvetica', 'BU', 12);
$pdf->Cell(0, 8, 'LAPORAN HASIL KEGIATAN', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$filterInfo = "Periode: " . ($start_date ? formatTanggalIndonesia($start_date) : 'Awal') . " s/d " . ($end_date ? formatTanggalIndonesia($end_date) : 'Akhir');
$pdf->Cell(0, 5, $filterInfo, 0, 1, 'C');
$pdf->Ln(5);

// ===================================================================
// --- BAGIAN TABEL (TIDAK DIUBAH, DIAMBIL DARI FILE ANDA) ---
// ===================================================================
if (!empty($reportsBySection)) {
    foreach ($reportsBySection as $sectionId => $sectionData) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->writeHTML('<h4 style="color: #333; background-color: #f0f0f0; padding: 8px; border-left: 4px solid #0056b3;">' . 
                       htmlspecialchars($sectionData['section_name']) . ' (' . count($sectionData['reports']) . ' laporan)</h4>', 
                       true, false, true, false, '');
        $pdf->Ln(2);

        if (!empty($sectionData['reports'])) {
            $header = ['No', 'Pelapor', 'Kategori', 'Keterangan', 'Tanggal', 'Jam', 'Foto'];
            $widths = [8, 30, 25, 45, 20, 15, 37]; 
            $rowHeight = 25; 

            $pdf->SetFillColor(242, 242, 242);
            $pdf->SetTextColor(0);
            $pdf->SetDrawColor(128, 128, 128);
            $pdf->SetFont('helvetica', 'B', 8);
            for ($i = 0; $i < count($header); $i++) {
                $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', 1);
            }
            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetFillColor(255);
            $no = 1;
            foreach ($sectionData['reports'] as $report) {
                $startY = $pdf->GetY();
                $pelaporText = htmlspecialchars($report['nama_lengkap']) . "\n" . '<small>(' . htmlspecialchars($report['jabatan']) . ')</small>';
                $kategoriText = htmlspecialchars($report['category_name']);
                $keteranganText = htmlspecialchars($report['keterangan']);
                $tanggalText = formatTanggalIndonesia($report['tanggal_pelaporan']);
                $jamText = htmlspecialchars($report['jam_pelaporan']);

                $pdf->MultiCell($widths[0], $rowHeight, $no++, 'LTRB', 'C', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $pdf->MultiCell($widths[1], $rowHeight, $pelaporText, 'LTRB', 'L', true, 0, '', '', true, 0, true, true, $rowHeight, 'M');
                $pdf->MultiCell($widths[2], $rowHeight, $kategoriText, 'LTRB', 'L', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $pdf->MultiCell($widths[3], $rowHeight, $keteranganText, 'LTRB', 'L', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $pdf->MultiCell($widths[4], $rowHeight, $tanggalText, 'LTRB', 'C', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                $pdf->MultiCell($widths[5], $rowHeight, $jamText, 'LTRB', 'C', true, 0, '', '', true, 0, false, true, $rowHeight, 'M');
                
                $currentX = $pdf->GetX();
                $pdf->Cell($widths[6], $rowHeight, '', 'LTRB', 0, 'C', true);
                if (!empty($report['foto_bukti'])) {
                    $imagePath = '../assets/uploads/' . $report['foto_bukti'];
                    if (file_exists($imagePath)) {
                        $imageX = $currentX + 2; $imageY = $startY + 2;
                        $imageWidth = $widths[6] - 4; $imageHeight = $rowHeight - 4;
                        try {
                            $pdf->Image($imagePath, $imageX, $imageY, $imageWidth, $imageHeight, '', '', '', false, 300, '', false, false, 1, false, false, false);
                        } catch (Exception $e) { /* Gagal load gambar */ }
                    }
                }
                $pdf->Ln($rowHeight);
            }
        }
        $pdf->Ln(5);
    }
} else {
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 20, 'Tidak ada data laporan yang sesuai dengan filter yang dipilih.', 0, 1, 'C');
}
// ===================================================================
// --- AKHIR BAGIAN TABEL ---
// ===================================================================


// 4. BAGIAN TANDA TANGAN
// -----------------------------------------------------------------
// Cek posisi Y saat ini, jika terlalu dekat ke bawah, tambahkan halaman baru
if ($pdf->GetY() > ($pdf->getPageHeight() - 60)) {
    $pdf->AddPage();
}
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 11);
$pdf->SetX(120);
$pdf->Cell(0, 5, 'Tanjungpinang, ' . formatTanggalIndonesia(date('Y-m-d')), 0, 1, 'L');
$pdf->SetX(120);
$pdf->Cell(0, 5, 'Mengetahui,', 0, 1, 'L');
$pdf->SetX(120);
$pdf->Cell(0, 5, 'Kepala Dinas Perhubungan', 0, 1, 'L');
$pdf->Ln(20); 
$pdf->SetX(120);
$pdf->SetFont('helvetica', 'BU', 11);
$pdf->Cell(0, 5, 'Nama Pejabat', 0, 1, 'L'); // <-- GANTI NAMA PEJABAT
$pdf->SetFont('helvetica', '', 11);
$pdf->SetX(120);
$pdf->Cell(0, 5, 'NIP. 19xxxxxxxx xxxxxxxx x xxx', 0, 1, 'L'); // <-- GANTI NIP


// 5. Output PDF
// -----------------------------------------------------------------
$filename = 'Laporan_Resmi_Dishub_' . date('Ymd_His') . '.pdf';
$pdf->Output($filename, 'I'); // 'I' untuk tampil di browser, 'D' untuk download

exit;
?>