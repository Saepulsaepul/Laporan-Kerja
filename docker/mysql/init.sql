-- init.sql
-- Database untuk aplikasi Pest Control
-- Final Fix: Login Admin + Login Pekerja

DROP DATABASE IF EXISTS website_pelaporan;
CREATE DATABASE website_pelaporan CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE website_pelaporan;

-- =========================
-- TABEL admin_users
-- =========================
DROP TABLE IF EXISTS admin_users;
CREATE TABLE admin_users (
  id INT NOT NULL AUTO_INCREMENT,
  nama VARCHAR(150) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  telepon VARCHAR(30) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
);

-- Admin Default
INSERT INTO admin_users (nama, username, password, telepon)
VALUES
('Administrator Sistem', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '081234567890');
-- Password = admin123

-- =========================
-- TABEL users (PEKERJA)
-- =========================
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INT NOT NULL AUTO_INCREMENT,
  nama VARCHAR(150) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(150),
  password VARCHAR(255) NOT NULL,
  telepon VARCHAR(30),
  jabatan VARCHAR(100) DEFAULT 'Pekerja Pest Control',
  status ENUM('Aktif', 'Nonaktif') DEFAULT 'Aktif',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
);

INSERT INTO users (nama, username, email, password, telepon, jabatan)
VALUES
('Budi Pekerja', 'budi', 'budi@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081298765432', 'Teknisi Pest Control');
-- Password contoh: pekerja123

-- =========================
-- TABEL services
-- =========================
DROP TABLE IF EXISTS services;
CREATE TABLE services (
  id INT NOT NULL AUTO_INCREMENT,
  kode_service VARCHAR(20) UNIQUE,
  nama_service VARCHAR(150) NOT NULL UNIQUE,
  deskripsi TEXT,
  durasi_menit INT,
  harga DECIMAL(12,2),
  kategori ENUM('Residential', 'Commercial', 'Industrial') DEFAULT 'Residential',
  status ENUM('Aktif', 'Nonaktif') DEFAULT 'Aktif',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
);

INSERT INTO services (kode_service, nama_service, deskripsi, durasi_menit, harga, kategori)
VALUES
('FOG-001', 'Fogging Nyamuk', 'Pengasapan area outdoor/indoor untuk pengendalian nyamuk dan serangga terbang', 60, 350000.00, 'Residential'),
('FUM-001', 'Fumigasi Gudang', 'Pengendalian hama gudang komprehensif untuk area penyimpanan', 180, 1500000.00, 'Industrial'),
('SPR-001', 'Penyemprotan Rumah', 'Insektisida aman residensial untuk indoor treatment', 45, 200000.00, 'Residential'),
('ROD-001', 'Rodent Control', 'Pengendalian tikus dan rodent lainnya dengan sistem monitoring', 90, 750000.00, 'Commercial'),
('TER-001', 'Termite Control', 'Pengendalian rayap dengan sistem baiting dan chemical barrier', 120, 1200000.00, 'Commercial');

-- =========================
-- TABEL customers
-- =========================
DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id INT NOT NULL AUTO_INCREMENT,
  nama_perusahaan VARCHAR(150) NOT NULL,
  nama_customer VARCHAR(150) NOT NULL,
  telepon VARCHAR(30),
  email VARCHAR(150),
  alamat TEXT,
  gedung VARCHAR(100),
  lantai VARCHAR(20),
  unit VARCHAR(50),
  jenis_layanan_id INT, -- Terhubung ke tabel services
  tanggal_mulai_kontrak DATE,
  tanggal_selesai_kontrak DATE,
  nilai_kontrak DECIMAL(15,2),
  status_kontrak ENUM('Aktif', 'Selesai', 'Ditangguhkan', 'Dibatalkan') DEFAULT 'Aktif',
  keterangan TEXT,
  status ENUM('Aktif', 'Nonaktif', 'Trial') DEFAULT 'Aktif',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_jenis_layanan (jenis_layanan_id),
  INDEX idx_status (status),
  INDEX idx_nama (nama_perusahaan, nama_customer),
  INDEX idx_kontrak (status_kontrak),
  FOREIGN KEY (jenis_layanan_id) REFERENCES services(id) ON DELETE SET NULL
);

INSERT INTO customers (
  nama_perusahaan, 
  nama_customer, 
  telepon, 
  email, 
  alamat, 
  gedung,
  lantai,
  unit,
  jenis_layanan_id, 
  tanggal_mulai_kontrak,
  tanggal_selesai_kontrak,
  nilai_kontrak, 
  status_kontrak,
  keterangan,
  status
)
VALUES
('PT. Industri Maju Jaya', 'Bapak Joko Susanto', '02112345678', 'joko@industrimaju.com', 'Jl. Industri Raya No.45 Jakarta Barat', 'Gedung Utama', 'Lantai 1', 'Area A-B', 2, '2024-01-01', '2025-12-31', 50000000.00, 'Aktif', 'Gudang produksi, butuh fumigasi rutin bulanan. Kontrak platinum - include emergency service', 'Aktif'),
('Ibu Siti Rahayu', 'Ibu Siti Rahayu', '02156789012', 'siti.rahayu@gmail.com', 'Apartemen Taman Anggrek', 'Tower B', 'Lantai 8', 'Unit 801', 3, '2024-07-01', '2024-12-31', 2400000.00, 'Aktif', 'Semprot bulanan untuk apartemen 3BR. Paket silver - residential', 'Aktif'),
('PT. Bangun Sejahtera', 'Bapak Rudi Hartono', '02134567890', 'rudi@bangunsejahtera.co.id', 'Komplek Perkantoran Sudirman', 'Tower 2', 'Lantai 15', 'Suite 1501-1505', 1, '2024-10-01', '2024-12-31', 3150000.00, 'Aktif', 'Fogging area parkir dan taman kantor. Trial period - evaluasi setelah 3 bulan', 'Trial'),
('PT. Retail Indonesia', 'Ibu Maya Sari', '02198765432', 'maya@retailindo.co.id', 'Mal Mega Kuningan', 'Mall Main Building', 'Lantai 3', 'Food Court Area', 4, '2024-06-01', '2025-05-31', 10800000.00, 'Aktif', 'Monitoring tikus area food court. Kontrak bulanan untuk rodent control', 'Aktif'),
('RS. Sehat Sentosa', 'Dr. Andi Wijaya', '02187654321', 'andi.wijaya@rsss.com', 'Jl. Kesehatan No. 123', 'Gedung Utama', 'Lantai Basement & 2', 'Gudang Farmasi & Ruang Operasi', 5, '2024-01-01', '2024-12-31', 15000000.00, 'Aktif', 'Pengendalian rayap di area kritis rumah sakit. Kontrak tahunan termite control - priority service', 'Aktif');

-- =========================
-- TABEL jadwal
-- =========================
DROP TABLE IF EXISTS jadwal;
CREATE TABLE jadwal (
  id INT NOT NULL AUTO_INCREMENT,
  kode_jadwal VARCHAR(50) UNIQUE,
  admin_id INT NOT NULL,
  pekerja_id INT,
  customer_id INT NOT NULL,
  service_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jam TIME NOT NULL,
  lokasi TEXT,
  durasi_estimasi INT, -- dalam menit
  status ENUM('Menunggu','Berjalan','Selesai','Dibatalkan') DEFAULT 'Menunggu',
  prioritas ENUM('Rendah', 'Sedang', 'Tinggi', 'Darurat') DEFAULT 'Sedang',
  catatan_admin TEXT,
  catatan_pekerja TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_tanggal (tanggal),
  INDEX idx_status (status),
  INDEX idx_pekerja (pekerja_id),
  INDEX idx_customer (customer_id),
  FOREIGN KEY (pekerja_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
);

-- Generate kode jadwal
INSERT INTO jadwal (kode_jadwal, admin_id, pekerja_id, customer_id, service_id, tanggal, jam, lokasi, durasi_estimasi, status, prioritas, catatan_admin)
VALUES
('JDW/2025/12/001', 1, 1, 1, 2, '2025-12-10', '08:00:00', 'Gudang A Loading Dock, Lantai 1', 180, 'Menunggu', 'Tinggi', 'Fumigasi gudang - bawa alat lengkap'),
('JDW/2025/12/002', 1, NULL, 2, 3, '2025-12-11', '09:30:00', 'Apartemen Tower B Lt.8 Unit 801', 45, 'Menunggu', 'Sedang', 'Semprot rumah rutin - customer sensitif aroma'),
('JDW/2025/12/003', 1, 1, 3, 1, '2025-12-12', '14:00:00', 'Parkir basement dan taman kantor, Tower 2', 60, 'Berjalan', 'Sedang', 'Fogging area outdoor - koordinasi dengan security'),
('JDW/2025/12/004', 1, NULL, 4, 4, '2025-12-13', '10:00:00', 'Food Court Area, Lantai 3 Mall', 90, 'Menunggu', 'Tinggi', 'Rodent control - periksa bait station'),
('JDW/2025/12/005', 1, 1, 5, 5, '2025-12-14', '13:00:00', 'Gudang Farmasi & Ruang Operasi', 120, 'Menunggu', 'Darurat', 'Termite control area kritis - prioritas tinggi');

-- =========================
-- TABEL reports
-- =========================
DROP TABLE IF EXISTS reports;
CREATE TABLE reports (
  id INT NOT NULL AUTO_INCREMENT,
  kode_laporan VARCHAR(50) UNIQUE,
  user_id INT NOT NULL,
  jadwal_id INT,
  customer_id INT,
  keterangan TEXT NOT NULL,
  bahan_digunakan TEXT,
  hasil_pengamatan TEXT,
  rekomendasi TEXT,
  foto_bukti VARCHAR(255),
  foto_sebelum VARCHAR(255),
  foto_sesudah VARCHAR(255),
  tanggal_pelaporan DATE NOT NULL,
  jam_mulai TIME,
  jam_selesai TIME,
  rating_customer INT DEFAULT 5,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_user (user_id),
  INDEX idx_tanggal (tanggal_pelaporan),
  INDEX idx_jadwal (jadwal_id),
  INDEX idx_customer (customer_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (jadwal_id) REFERENCES jadwal(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

-- Generate kode laporan
INSERT INTO reports (kode_laporan, user_id, jadwal_id, customer_id, keterangan, bahan_digunakan, hasil_pengamatan, rekomendasi, tanggal_pelaporan, jam_mulai, jam_selesai, rating_customer)
VALUES
('RPT/2025/12/001', 1, 1, 1, 'Pekerjaan berjalan lancar, sisa obat 10%', 'Fumigant X-200 2kg, Alat fogger professional', 'Area gudang sudah tercover semua, monitoring rodensia perlu dilanjutkan', 'Lanjutkan treatment bulan depan, tambah bait station untuk tikus', '2025-12-10', '08:15:00', '11:30:00', 5);

-- =========================
-- VIEW untuk dashboard
-- =========================
DROP VIEW IF EXISTS view_dashboard_pekerja;
CREATE VIEW view_dashboard_pekerja AS
SELECT 
    u.id as user_id,
    u.nama as nama_pekerja,
    COUNT(DISTINCT r.id) as total_laporan,
    COUNT(DISTINCT CASE WHEN DATE(r.tanggal_pelaporan) = CURDATE() THEN r.id END) as laporan_hari_ini,
    COUNT(DISTINCT j.id) as total_jadwal,
    COUNT(DISTINCT CASE WHEN j.tanggal = CURDATE() AND j.status IN ('Menunggu', 'Berjalan') THEN j.id END) as jadwal_hari_ini,
    COUNT(DISTINCT CASE WHEN j.status = 'Selesai' THEN j.id END) as jadwal_selesai,
    COUNT(DISTINCT c.id) as total_customer
FROM users u
LEFT JOIN reports r ON u.id = r.user_id
LEFT JOIN jadwal j ON u.id = j.pekerja_id
LEFT JOIN customers c ON j.customer_id = c.id
GROUP BY u.id, u.nama;

-- =========================
-- PROSEDUR untuk generate kode otomatis
-- =========================
DELIMITER //
DROP PROCEDURE IF EXISTS GenerateKodeJadwal //
CREATE PROCEDURE GenerateKodeJadwal(OUT kode VARCHAR(50))
BEGIN
    DECLARE tahun VARCHAR(4);
    DECLARE bulan VARCHAR(2);
    DECLARE sequence INT;
    
    SET tahun = YEAR(CURDATE());
    SET bulan = LPAD(MONTH(CURDATE()), 2, '0');
    
    -- Cari sequence terakhir untuk bulan ini
    SELECT COALESCE(MAX(CAST(SUBSTRING(kode_jadwal, 14) AS UNSIGNED)), 0) + 1 
    INTO sequence
    FROM jadwal 
    WHERE kode_jadwal LIKE CONCAT('JDW/', tahun, '/', bulan, '/%');
    
    SET kode = CONCAT('JDW/', tahun, '/', bulan, '/', LPAD(sequence, 3, '0'));
END //

DROP PROCEDURE IF EXISTS GenerateKodeLaporan //
CREATE PROCEDURE GenerateKodeLaporan(OUT kode VARCHAR(50))
BEGIN
    DECLARE tahun VARCHAR(4);
    DECLARE bulan VARCHAR(2);
    DECLARE sequence INT;
    
    SET tahun = YEAR(CURDATE());
    SET bulan = LPAD(MONTH(CURDATE()), 2, '0');
    
    -- Cari sequence terakhir untuk bulan ini
    SELECT COALESCE(MAX(CAST(SUBSTRING(kode_laporan, 14) AS UNSIGNED)), 0) + 1 
    INTO sequence
    FROM reports 
    WHERE kode_laporan LIKE CONCAT('RPT/', tahun, '/', bulan, '/%');
    
    SET kode = CONCAT('RPT/', tahun, '/', bulan, '/', LPAD(sequence, 3, '0'));
END //

DELIMITER ;

-- =========================
-- TRIGGER untuk update status jadwal
-- =========================
DELIMITER //
DROP TRIGGER IF EXISTS after_report_insert //
CREATE TRIGGER after_report_insert
AFTER INSERT ON reports
FOR EACH ROW
BEGIN
    -- Update status jadwal menjadi 'Selesai' jika ada jadwal_id
    IF NEW.jadwal_id IS NOT NULL THEN
        UPDATE jadwal 
        SET status = 'Selesai', 
            updated_at = NOW()
        WHERE id = NEW.jadwal_id;
    END IF;
END //

DELIMITER ;

-- =========================
-- INDEX tambahan untuk performa
-- =========================
CREATE INDEX idx_reports_tanggal_user ON reports(tanggal_pelaporan, user_id);
CREATE INDEX idx_jadwal_tanggal_status ON jadwal(tanggal, status);
CREATE INDEX idx_customers_nama ON customers(nama_perusahaan, nama_customer);
CREATE INDEX idx_customers_kontrak ON customers(tanggal_selesai_kontrak, status_kontrak);

-- =========================
-- DATA SAMPLE TAMBAHAN
-- =========================
INSERT INTO users (nama, username, email, password, telepon, jabatan)
VALUES
('Sari Teknisi', 'sari', 'sari@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081387654321', 'Teknisi Pest Control'),
('Anto Supervisor', 'anto', 'anto@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081456789012', 'Supervisor Lapangan');

-- Tambah jadwal untuk pekerja lain
INSERT INTO jadwal (kode_jadwal, admin_id, pekerja_id, customer_id, service_id, tanggal, jam, lokasi, durasi_estimasi, status, prioritas, catatan_admin)
VALUES
('JDW/2025/12/006', 1, 2, 4, 4, '2025-12-15', '11:00:00', 'Food Court Area, Lantai 3', 90, 'Menunggu', 'Sedang', 'Check bait station dan lapor jumlah tikus'),
('JDW/2025/12/007', 1, 3, 5, 5, '2025-12-16', '09:00:00', 'Ruang Operasi 1 & 2', 120, 'Menunggu', 'Tinggi', 'Termite inspection - area steril');

COMMIT;