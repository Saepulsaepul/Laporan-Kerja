<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

// Cek login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pekerja') {
    http_response_code(403);
    echo '<div class="alert alert-danger">Akses ditolak!</div>';
    exit();
}

// Cek parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo '<div class="alert alert-danger">ID laporan tidak valid!</div>';
    exit();
}

$report_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

$pdo = getConnection();

try {
    // Ambil detail laporan sesuai user
    $sql = "
        SELECT 
            r.*,
            r.id as report_id,
            r.nomor_kunjungan,
            c.nama_customer,
            c.nama_perusahaan,
            c.telepon as customer_telepon,
            c.alamat as customer_alamat,
            s.nama_service,
            s.kode_service,
            s.deskripsi as deskripsi_service,
            s.harga as harga_service,
            j.tanggal as jadwal_tanggal,
            j.jam as jadwal_jam,
            j.lokasi as jadwal_lokasi,
            j.prioritas as jadwal_prioritas,
            j.jenis_periode as jadwal_periode,
            j.jumlah_kunjungan as jadwal_total_kunjungan,
            j.catatan_admin as jadwal_catatan,
            u.nama as pekerja_nama,
            u.jabatan as pekerja_jabatan
        FROM reports r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN services s ON r.service_id = s.id
        LEFT JOIN jadwal j ON r.jadwal_id = j.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.user_id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$report_id, $user_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        http_response_code(404);
        echo '<div class="alert alert-danger">Laporan tidak ditemukan!</div>';
        exit();
    }
    
    // Fungsi helper
  
    
    // Start output
    ob_start();
    ?>
    
    <div class="modal-section">
        <div class="row">
            <div class="col-md-6">
                <div class="modal-label">Kode Laporan</div>
                <div class="modal-value fw-bold text-primary"><?php echo htmlspecialchars($report['kode_laporan']); ?></div>
            </div>
            <div class="col-md-6">
                <div class="modal-label">Tanggal Pelaporan</div>
                <div class="modal-value">
                    <?php echo formatTanggalIndonesia($report['tanggal_pelaporan']); ?>
                    <?php if (!empty($report['jam_mulai'])): ?>
                        <br><small class="text-muted">
                            Waktu: <?php echo date('H:i', strtotime($report['jam_mulai'])); ?>
                            <?php if (!empty($report['jam_selesai'])): ?>
                                - <?php echo date('H:i', strtotime($report['jam_selesai'])); ?>
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal-section">
        <div class="row">
            <div class="col-md-6">
                <div class="modal-label">Customer</div>
                <div class="modal-value">
                    <strong><?php echo htmlspecialchars($report['nama_customer'] ?: $report['nama_perusahaan']); ?></strong>
                    <?php if (!empty($report['customer_telepon'])): ?>
                        <br><small class="text-muted">Telp: <?php echo htmlspecialchars($report['customer_telepon']); ?></small>
                    <?php endif; ?>
                    <?php if (!empty($report['customer_alamat'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($report['customer_alamat']); ?></small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="modal-label">Layanan</div>
                <div class="modal-value">
                    <strong><?php echo htmlspecialchars($report['nama_service']); ?></strong>
                    <?php if (!empty($report['kode_service'])): ?>
                        <br><small class="text-muted">Kode: <?php echo htmlspecialchars($report['kode_service']); ?></small>
                    <?php endif; ?>
                    <?php if (!empty($report['harga_service'])): ?>
                        <br><small class="text-muted">Harga: Rp <?php echo number_format($report['harga_service'], 0, ',', '.'); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($report['jadwal_lokasi'])): ?>
    <div class="modal-section">
        <div class="modal-label">Lokasi Kunjungan</div>
        <div class="modal-value">
            <?php echo htmlspecialchars($report['jadwal_lokasi']); ?>
            <?php if (!empty($report['jadwal_tanggal'])): ?>
                <br><small class="text-muted">
                    Jadwal: <?php echo formatTanggalIndonesia($report['jadwal_tanggal']); ?>
                    <?php if (!empty($report['jadwal_jam'])): ?>
                        pukul <?php echo date('H:i', strtotime($report['jadwal_jam'])); ?>
                    <?php endif; ?>
                </small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($report['jadwal_periode']) && $report['jadwal_periode'] != 'Sekali'): ?>
    <div class="modal-section">
        <div class="modal-label">Jadwal Berulang</div>
        <div class="modal-value">
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-primary"><?php echo ucfirst($report['jadwal_periode']); ?></span>
                <span class="badge bg-info">Kunjungan <?php echo $report['nomor_kunjungan']; ?>/<?php echo $report['jadwal_total_kunjungan']; ?></span>
                <?php if (!empty($report['jadwal_prioritas'])): ?>
                    <span class="badge bg-warning"><?php echo ucfirst($report['jadwal_prioritas']); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="modal-section">
        <div class="modal-label">Keterangan Pekerjaan</div>
        <div class="modal-value">
            <?php echo nl2br(htmlspecialchars($report['keterangan'] ?? 'Tidak ada keterangan')); ?>
        </div>
    </div>
    
    <?php if (!empty($report['bahan_digunakan'])): ?>
    <div class="modal-section">
        <div class="modal-label">Bahan yang Digunakan</div>
        <div class="modal-value">
            <?php echo nl2br(htmlspecialchars($report['bahan_digunakan'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($report['hasil_pengamatan'])): ?>
    <div class="modal-section">
        <div class="modal-label">Hasil Pengamatan</div>
        <div class="modal-value">
            <?php echo nl2br(htmlspecialchars($report['hasil_pengamatan'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($report['rekomendasi'])): ?>
    <div class="modal-section">
        <div class="modal-label">Rekomendasi</div>
        <div class="modal-value">
            <?php echo nl2br(htmlspecialchars($report['rekomendasi'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- <?php if ($report['rating_customer']): ?>
    <div class="modal-section">
        <div class="modal-label">Rating Customer</div>
        <div class="modal-value">
            <?php 
            for ($i = 1; $i <= 5; $i++): 
                if ($i <= $report['rating_customer']):
            ?>
                <i class="fas fa-star text-warning"></i>
            <?php else: ?>
                <i class="far fa-star text-muted"></i>
            <?php 
                endif;
            endfor; 
            ?>
            <span class="ms-2">(<?php echo $report['rating_customer']; ?>/5)</span>
        </div>
    </div>
    <?php endif; ?> -->
    
    <?php if (!empty($report['foto_sebelum']) || !empty($report['foto_bukti']) || !empty($report['foto_sesudah'])): ?>
    <div class="modal-section">
        <div class="modal-label">Foto Dokumentasi</div>
        <div class="modal-value">
            <div class="modal-photo-grid">
                <?php if (!empty($report['foto_sebelum'])): ?>
                <div class="modal-photo-item">
                    <img src="../assets/uploads/<?php echo htmlspecialchars($report['foto_sebelum']); ?>" 
                         class="modal-photo" 
                         onclick="viewPhoto('../assets/uploads/<?php echo htmlspecialchars($report['foto_sebelum']); ?>')"
                         alt="Foto Sebelum">
                    <div class="modal-photo-caption">Sebelum</div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($report['foto_bukti'])): ?>
                <div class="modal-photo-item">
                    <img src="../assets/uploads/<?php echo htmlspecialchars($report['foto_bukti']); ?>" 
                         class="modal-photo" 
                         onclick="viewPhoto('../assets/uploads/<?php echo htmlspecialchars($report['foto_bukti']); ?>')"
                         alt="Foto Bukti">
                    <div class="modal-photo-caption">Bukti Pekerjaan</div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($report['foto_sesudah'])): ?>
                <div class="modal-photo-item">
                    <img src="../assets/uploads/<?php echo htmlspecialchars($report['foto_sesudah']); ?>" 
                         class="modal-photo" 
                         onclick="viewPhoto('../assets/uploads/<?php echo htmlspecialchars($report['foto_sesudah']); ?>')"
                         alt="Foto Sesudah">
                    <div class="modal-photo-caption">Sesudah</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="modal-section">
        <div class="row">
            <div class="col-md-6">
                <div class="modal-label">Dilaporkan Oleh</div>
                <div class="modal-value">
                    <strong><?php echo htmlspecialchars($report['pekerja_nama']); ?></strong>
                    <br><small class="text-muted"><?php echo htmlspecialchars($report['pekerja_jabatan']); ?></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="modal-label">Dibuat Pada</div>
                <div class="modal-value">
                    <?php echo date('d/m/Y H:i', strtotime($report['created_at'])); ?>
                    <br><small class="text-muted">Terakhir diupdate: <?php echo date('d/m/Y H:i', strtotime($report['updated_at'])); ?></small>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($report['jadwal_catatan'])): ?>
    <div class="modal-section bg-light rounded p-3">
        <div class="modal-label text-warning">
            <i class="fas fa-sticky-note me-2"></i>Catatan Admin untuk Jadwal
        </div>
        <div class="modal-value">
            <?php echo nl2br(htmlspecialchars($report['jadwal_catatan'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php
    $output = ob_get_clean();
    echo $output;
    
} catch (PDOException $e) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>