-- init.sql (VERSI SIMPEL DAN PASTI BERJALAN)
-- Database untuk aplikasi Pest Control

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
  tanggal_mulai_kontrak DATE,
  tanggal_selesai_kontrak DATE,
  nilai_kontrak DECIMAL(15,2),
  status_kontrak ENUM('Aktif', 'Selesai', 'Ditangguhkan', 'Dibatalkan') DEFAULT 'Aktif',
  keterangan TEXT,
  status ENUM('Aktif', 'Nonaktif', 'Trial') DEFAULT 'Aktif',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_status (status),
  INDEX idx_nama (nama_perusahaan, nama_customer),
  INDEX idx_kontrak (status_kontrak)
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
  tanggal_mulai_kontrak,
  tanggal_selesai_kontrak,
  nilai_kontrak, 
  status_kontrak,
  keterangan,
  status
)
VALUES
('PT. Industri Maju Jaya', 'Bapak Joko Susanto', '02112345678', 'joko@industrimaju.com', 'Jl. Industri Raya No.45 Jakarta Barat', 'Gedung Utama', 'Lantai 1', 'Area A-B', '2024-01-01', '2025-12-31', 50000000.00, 'Aktif', 'Gudang produksi, butuh fumigasi rutin bulanan. Kontrak platinum - include emergency service', 'Aktif'),
('Ibu Siti Rahayu', 'Ibu Siti Rahayu', '02156789012', 'siti.rahayu@gmail.com', 'Apartemen Taman Anggrek', 'Tower B', 'Lantai 8', 'Unit 801', '2024-07-01', '2024-12-31', 2400000.00, 'Aktif', 'Semprot bulanan untuk apartemen 3BR. Paket silver - residential', 'Aktif'),
('PT. Bangun Sejahtera', 'Bapak Rudi Hartono', '02134567890', 'rudi@bangunsejahtera.co.id', 'Komplek Perkantoran Sudirman', 'Tower 2', 'Lantai 15', 'Suite 1501-1505', '2024-10-01', '2024-12-31', 3150000.00, 'Aktif', 'Fogging area parkir dan taman kantor. Trial period - evaluasi setelah 3 bulan', 'Trial'),
('PT. Retail Indonesia', 'Ibu Maya Sari', '02198765432', 'maya@retailindo.co.id', 'Mal Mega Kuningan', 'Mall Main Building', 'Lantai 3', 'Food Court Area', '2024-06-01', '2025-05-31', 10800000.00, 'Aktif', 'Monitoring tikus area food court. Kontrak bulanan untuk rodent control', 'Aktif'),
('RS. Sehat Sentosa', 'Dr. Andi Wijaya', '02187654321', 'andi.wijaya@rsss.com', 'Jl. Kesehatan No. 123', 'Gedung Utama', 'Lantai Basement & 2', 'Gudang Farmasi & Ruang Operasi', '2024-01-01', '2024-12-31', 15000000.00, 'Aktif', 'Pengendalian rayap di area kritis rumah sakit. Kontrak tahunan termite control - priority service', 'Aktif');

-- =========================
-- TABEL customer_services
-- =========================
DROP TABLE IF EXISTS customer_services;
CREATE TABLE customer_services (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  service_id INT NOT NULL,
  tanggal_mulai DATE,
  tanggal_selesai DATE,
  nilai_kontrak DECIMAL(15,2),
  status ENUM('Aktif', 'Selesai', 'Ditangguhkan', 'Dibatalkan') DEFAULT 'Aktif',
  keterangan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_customer_service (customer_id, service_id),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
  INDEX idx_customer_service (customer_id, service_id)
);

INSERT INTO customer_services (customer_id, service_id, tanggal_mulai, tanggal_selesai, nilai_kontrak, status, keterangan)
VALUES
-- Customer 1 punya 2 layanan
(1, 2, '2024-01-01', '2025-12-31', 40000000.00, 'Aktif', 'Fumigasi gudang utama'),
(1, 4, '2024-01-01', '2025-12-31', 10000000.00, 'Aktif', 'Rodent control area gudang'),
-- Customer 2 punya 1 layanan
(2, 3, '2024-07-01', '2024-12-31', 2400000.00, 'Aktif', 'Penyemprotan rutin bulanan'),
-- Customer 3 punya 1 layanan
(3, 1, '2024-10-01', '2024-12-31', 3150000.00, 'Aktif', 'Fogging area perkantoran'),
-- Customer 4 punya 2 layanan
(4, 4, '2024-06-01', '2025-05-31', 8000000.00, 'Aktif', 'Rodent control food court'),
(4, 1, '2024-06-01', '2025-05-31', 2800000.00, 'Aktif', 'Fogging area sampah'),
-- Customer 5 punya 2 layanan
(5, 5, '2024-01-01', '2024-12-31', 12000000.00, 'Aktif', 'Termite control utama'),
(5, 3, '2024-01-01', '2024-12-31', 3000000.00, 'Aktif', 'Penyemprotan ruang operasi');

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
  durasi_estimasi INT,
  status ENUM('Menunggu','Berjalan','Selesai','Dibatalkan') DEFAULT 'Menunggu',
  prioritas ENUM('Rendah', 'Sedang', 'Tinggi', 'Darurat') DEFAULT 'Sedang',
  catatan_admin TEXT,
  catatan_pekerja TEXT,
  
  jenis_periode ENUM('Sekali', 'Harian', 'Mingguan', 'Bulanan', 'Tahunan') DEFAULT 'Sekali',
  jumlah_kunjungan INT DEFAULT 1,
  kunjungan_berjalan INT DEFAULT 0,
  tanggal_selesai_periode DATE,
  parent_jadwal_id INT NULL,
  
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  INDEX idx_tanggal (tanggal),
  INDEX idx_status (status),
  INDEX idx_pekerja (pekerja_id),
  INDEX idx_customer (customer_id),
  INDEX idx_parent_jadwal (parent_jadwal_id),
  INDEX idx_jenis_periode (jenis_periode),
  
  FOREIGN KEY (pekerja_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_jadwal_id) REFERENCES jadwal(id) ON DELETE SET NULL
);

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
  service_id INT,
  nomor_kunjungan INT DEFAULT 1,
  
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
  INDEX idx_service (service_id),
  INDEX idx_nomor_kunjungan (nomor_kunjungan),
  UNIQUE KEY unique_jadwal_kunjungan (jadwal_id, nomor_kunjungan),
  
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (jadwal_id) REFERENCES jadwal(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
);

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

-- View untuk customer dengan layanan
DROP VIEW IF EXISTS view_customer_services;
CREATE VIEW view_customer_services AS
SELECT 
    c.id as customer_id,
    c.nama_perusahaan,
    c.nama_customer,
    c.telepon,
    c.email,
    c.status as customer_status,
    GROUP_CONCAT(DISTINCT CONCAT(s.nama_service, ' (', s.kode_service, ')') SEPARATOR ', ') as services_list,
    GROUP_CONCAT(DISTINCT s.id) as service_ids,
    COUNT(DISTINCT cs.id) as total_services,
    MIN(cs.tanggal_mulai) as kontrak_mulai,
    MAX(cs.tanggal_selesai) as kontrak_selesai,
    SUM(cs.nilai_kontrak) as total_nilai_kontrak
FROM customers c
LEFT JOIN customer_services cs ON c.id = cs.customer_id
LEFT JOIN services s ON cs.service_id = s.id
GROUP BY c.id;

-- View untuk jadwal dengan informasi lengkap
DROP VIEW IF EXISTS view_jadwal_detail;
CREATE VIEW view_jadwal_detail AS
SELECT 
    j.*,
    c.nama_perusahaan,
    c.nama_customer,
    c.telepon as customer_telepon,
    c.email as customer_email,
    c.gedung,
    c.lantai,
    c.unit,
    s.nama_service,
    s.kode_service,
    s.harga,
    s.durasi_menit,
    s.kategori,
    u.nama as pekerja_nama,
    u.jabatan as pekerja_jabatan,
    u.telepon as pekerja_telepon,
    a.nama as admin_nama,
    
    (SELECT COUNT(*) FROM reports r WHERE r.jadwal_id = j.id) as total_laporan,
    
    (SELECT MAX(r.nomor_kunjungan) FROM reports r WHERE r.jadwal_id = j.id) as last_reported_kunjungan,
    
    CASE 
        WHEN j.jenis_periode = 'Sekali' THEN 'Sekali'
        WHEN j.kunjungan_berjalan >= j.jumlah_kunjungan THEN 'Selesai Semua'
        ELSE CONCAT(j.kunjungan_berjalan, '/', j.jumlah_kunjungan, ' kunjungan')
    END as progress_status
    
FROM jadwal j
LEFT JOIN customers c ON j.customer_id = c.id
LEFT JOIN services s ON j.service_id = s.id
LEFT JOIN users u ON j.pekerja_id = u.id
LEFT JOIN admin_users a ON j.admin_id = a.id;

-- =========================
-- FUNGSI SIMPLE UNTUK GENERATE KODE
-- =========================
DELIMITER //

-- Fungsi untuk generate kode jadwal (SIMPLE VERSION)
CREATE FUNCTION fn_generate_kode_jadwal(p_tanggal DATE, p_is_recurring BOOLEAN) 
RETURNS VARCHAR(50)
DETERMINISTIC
BEGIN
    DECLARE v_tahun VARCHAR(4);
    DECLARE v_bulan VARCHAR(2);
    DECLARE v_sequence INT;
    DECLARE v_kode VARCHAR(50);
    DECLARE v_counter INT DEFAULT 1;
    
    SET v_tahun = YEAR(p_tanggal);
    SET v_bulan = LPAD(MONTH(p_tanggal), 2, '0');
    
    -- Cari sequence terakhir untuk bulan ini
    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(kode_jadwal, '/', -1), '-K', 1) AS UNSIGNED)), 0) + 1 
    INTO v_sequence
    FROM jadwal 
    WHERE kode_jadwal LIKE CONCAT('JDW/', v_tahun, '/', v_bulan, '/%');
    
    -- Buat kode dasar
    SET v_kode = CONCAT('JDW/', v_tahun, '/', v_bulan, '/', LPAD(v_sequence, 3, '0'));
    
    -- Jika recurring, tambah suffix -K01
    IF p_is_recurring THEN
        SET v_kode = CONCAT(v_kode, '-K01');
    END IF;
    
    -- Pastikan kode unik
    WHILE EXISTS (SELECT 1 FROM jadwal WHERE kode_jadwal = v_kode) DO
        IF p_is_recurring THEN
            SET v_kode = CONCAT('JDW/', v_tahun, '/', v_bulan, '/', LPAD(v_sequence + v_counter, 3, '0'), '-K01');
        ELSE
            SET v_kode = CONCAT('JDW/', v_tahun, '/', v_bulan, '/', LPAD(v_sequence + v_counter, 3, '0'));
        END IF;
        SET v_counter = v_counter + 1;
    END WHILE;
    
    RETURN v_kode;
END //

-- Fungsi untuk generate kode laporan
CREATE FUNCTION fn_generate_kode_laporan(p_tanggal DATE) 
RETURNS VARCHAR(50)
DETERMINISTIC
BEGIN
    DECLARE v_tahun VARCHAR(4);
    DECLARE v_bulan VARCHAR(2);
    DECLARE v_sequence INT;
    DECLARE v_kode VARCHAR(50);
    DECLARE v_counter INT DEFAULT 1;
    
    SET v_tahun = YEAR(p_tanggal);
    SET v_bulan = LPAD(MONTH(p_tanggal), 2, '0');
    
    -- Cari sequence terakhir untuk bulan ini
    SELECT COALESCE(MAX(CAST(SUBSTRING(kode_laporan, 14) AS UNSIGNED)), 0) + 1 
    INTO v_sequence
    FROM reports 
    WHERE kode_laporan LIKE CONCAT('RPT/', v_tahun, '/', v_bulan, '/%');
    
    -- Buat kode dasar
    SET v_kode = CONCAT('RPT/', v_tahun, '/', v_bulan, '/', LPAD(v_sequence, 3, '0'));
    
    -- Pastikan kode unik
    WHILE EXISTS (SELECT 1 FROM reports WHERE kode_laporan = v_kode) DO
        SET v_kode = CONCAT('RPT/', v_tahun, '/', v_bulan, '/', LPAD(v_sequence + v_counter, 3, '0'));
        SET v_counter = v_counter + 1;
    END WHILE;
    
    RETURN v_kode;
END //

DELIMITER ;

-- =========================
-- TRIGGER SEDERHANA
-- =========================
DELIMITER //

-- Trigger untuk generate kode jadwal otomatis
CREATE TRIGGER tr_before_jadwal_insert
BEFORE INSERT ON jadwal
FOR EACH ROW
BEGIN
    -- Jika kode_jadwal belum diisi, generate otomatis
    IF NEW.kode_jadwal IS NULL THEN
        SET NEW.kode_jadwal = fn_generate_kode_jadwal(NEW.tanggal, NEW.jenis_periode != 'Sekali');
    END IF;
END //

-- Trigger untuk generate kode laporan otomatis
CREATE TRIGGER tr_before_reports_insert
BEFORE INSERT ON reports
FOR EACH ROW
BEGIN
    -- Jika kode_laporan belum diisi, generate otomatis
    IF NEW.kode_laporan IS NULL THEN
        SET NEW.kode_laporan = fn_generate_kode_laporan(NEW.tanggal_pelaporan);
    END IF;
    
    -- Auto calculate nomor_kunjungan jika dari jadwal
    IF NEW.nomor_kunjungan IS NULL AND NEW.jadwal_id IS NOT NULL THEN
        SELECT COALESCE(MAX(nomor_kunjungan), 0) + 1 INTO NEW.nomor_kunjungan
        FROM reports 
        WHERE jadwal_id = NEW.jadwal_id;
        
        IF NEW.nomor_kunjungan IS NULL THEN
            SET NEW.nomor_kunjungan = 1;
        END IF;
    END IF;
END //

-- Trigger untuk update kunjungan_berjalan setelah laporan dibuat
CREATE TRIGGER tr_after_report_insert
AFTER INSERT ON reports
FOR EACH ROW
BEGIN
    -- Update kunjungan_berjalan di jadwal
    IF NEW.jadwal_id IS NOT NULL THEN
        UPDATE jadwal 
        SET kunjungan_berjalan = (
            SELECT COALESCE(MAX(nomor_kunjungan), 0)
            FROM reports 
            WHERE jadwal_id = NEW.jadwal_id
        ),
        updated_at = NOW()
        WHERE id = NEW.jadwal_id;
        
        -- Update status jadi Selesai jika semua kunjungan sudah dilaporkan
        UPDATE jadwal j
        SET j.status = 'Selesai',
            j.updated_at = NOW()
        WHERE j.id = NEW.jadwal_id
        AND j.kunjungan_berjalan >= j.jumlah_kunjungan;
    END IF;
END //

DELIMITER ;

-- =========================
-- INDEX tambahan
-- =========================
CREATE INDEX idx_reports_tanggal_user ON reports(tanggal_pelaporan, user_id);
CREATE INDEX idx_jadwal_tanggal_status ON jadwal(tanggal, status);
CREATE INDEX idx_customers_nama ON customers(nama_perusahaan, nama_customer);
CREATE INDEX idx_customers_kontrak ON customers(tanggal_selesai_kontrak, status_kontrak);
CREATE INDEX idx_customer_services_customer ON customer_services(customer_id);
CREATE INDEX idx_customer_services_service ON customer_services(service_id);
CREATE INDEX idx_reports_jadwal_kunjungan ON reports(jadwal_id, nomor_kunjungan);
CREATE INDEX idx_jadwal_parent ON jadwal(parent_jadwal_id);

-- =========================
-- INSERT DATA SAMPLE (SIMPLE VERSION)
-- =========================

-- Tambah users sample
INSERT INTO users (nama, username, email, password, telepon, jabatan)
VALUES
('Sari Teknisi', 'sari', 'sari@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081387654321', 'Teknisi Pest Control'),
('Anto Supervisor', 'anto', 'anto@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081456789012', 'Supervisor Lapangan');

-- Tambah customer baru
INSERT INTO customers (
  nama_perusahaan, 
  nama_customer, 
  telepon, 
  email, 
  alamat, 
  gedung,
  lantai,
  unit,
  tanggal_mulai_kontrak,
  tanggal_selesai_kontrak,
  status_kontrak,
  keterangan,
  status
)
VALUES
('PT. Hotel Grand Indonesia', 'Bapak Agus Santoso', '02111122233', 'agus@grandhotel.com', 'Jl. MH Thamrin No.1', 'Main Building', 'Lantai 1-10', 'Semua Area', '2024-03-01', '2025-02-28', 'Aktif', 'Hotel 5-star, butuh comprehensive pest control', 'Aktif');

SET @hotel_id = LAST_INSERT_ID();

-- Tambah services untuk customer baru
INSERT INTO customer_services (customer_id, service_id, tanggal_mulai, tanggal_selesai, nilai_kontrak, status, keterangan)
VALUES
(@hotel_id, 1, '2024-03-01', '2025-02-28', 6000000.00, 'Aktif', 'Fogging area outdoor hotel'),
(@hotel_id, 3, '2024-03-01', '2025-02-28', 8000000.00, 'Aktif', 'Penyemprotan kamar & koridor');

-- =========================
-- INSERT JADWAL SAMPLE (TRIGGER AKAN GENERATE KODE OTOMATIS)
-- =========================

-- Insert jadwal sample - trigger akan generate kode otomatis
INSERT INTO jadwal (admin_id, pekerja_id, customer_id, service_id, tanggal, jam, lokasi, durasi_estimasi, status, prioritas, catatan_admin, jenis_periode, jumlah_kunjungan)
VALUES
-- Jadwal sekali
(1, 1, 1, 2, '2025-12-10', '08:00:00', 'Gudang A Loading Dock, Lantai 1', 180, 'Menunggu', 'Tinggi', 'Fumigasi gudang - bawa alat lengkap', 'Sekali', 1),
(1, NULL, 2, 3, '2025-12-11', '09:30:00', 'Apartemen Tower B Lt.8 Unit 801', 45, 'Menunggu', 'Sedang', 'Semprot rumah rutin - customer sensitif aroma', 'Sekali', 1),
-- Jadwal berulang (bulanan 3x)
(1, 1, 3, 1, '2025-12-12', '14:00:00', 'Parkir basement dan taman kantor, Tower 2', 60, 'Berjalan', 'Sedang', 'Fogging area outdoor - koordinasi dengan security', 'Bulanan', 3),
-- Jadwal berulang (mingguan 4x)
(1, NULL, 4, 4, '2025-12-13', '10:00:00', 'Food Court Area, Lantai 3 Mall', 90, 'Menunggu', 'Tinggi', 'Rodent control - periksa bait station', 'Mingguan', 4),
-- Jadwal berulang (harian 5x)
(1, 1, 5, 5, '2025-12-14', '13:00:00', 'Gudang Farmasi & Ruang Operasi', 120, 'Menunggu', 'Darurat', 'Termite control area kritis - prioritas tinggi', 'Harian', 5);

-- Buat jadwal berulang manual (child schedules)
INSERT INTO jadwal (admin_id, pekerja_id, customer_id, service_id, tanggal, jam, lokasi, durasi_estimasi, status, prioritas, catatan_admin, jenis_periode, jumlah_kunjungan, parent_jadwal_id, kunjungan_berjalan)
SELECT 
    admin_id, pekerja_id, customer_id, service_id,
    DATE_ADD(tanggal, INTERVAL 1 MONTH),
    jam, lokasi, durasi_estimasi, 'Menunggu', prioritas, catatan_admin,
    jenis_periode, jumlah_kunjungan, id, 1
FROM jadwal 
WHERE jenis_periode = 'Bulanan' 
AND parent_jadwal_id IS NULL 
AND jumlah_kunjungan > 1
LIMIT 1;

INSERT INTO jadwal (admin_id, pekerja_id, customer_id, service_id, tanggal, jam, lokasi, durasi_estimasi, status, prioritas, catatan_admin, jenis_periode, jumlah_kunjungan, parent_jadwal_id, kunjungan_berjalan)
SELECT 
    admin_id, pekerja_id, customer_id, service_id,
    DATE_ADD(tanggal, INTERVAL 2 MONTH),
    jam, lokasi, durasi_estimasi, 'Menunggu', prioritas, catatan_admin,
    jenis_periode, jumlah_kunjungan, id, 2
FROM jadwal 
WHERE jenis_periode = 'Bulanan' 
AND parent_jadwal_id IS NULL 
AND jumlah_kunjungan > 1
LIMIT 1;

-- =========================
-- INSERT REPORTS SAMPLE
-- =========================

-- Insert reports sample - trigger akan generate kode otomatis
INSERT INTO reports (user_id, jadwal_id, customer_id, service_id, nomor_kunjungan, keterangan, bahan_digunakan, hasil_pengamatan, rekomendasi, tanggal_pelaporan, jam_mulai, jam_selesai, rating_customer)
VALUES
-- Laporan untuk jadwal sekali
(1, 1, 1, 2, 1, 'Pekerjaan berjalan lancar, sisa obat 10%', 'Fumigant X-200 2kg, Alat fogger professional', 'Area gudang sudah tercover semua, monitoring rodensia perlu dilanjutkan', 'Lanjutkan treatment bulan depan, tambah bait station untuk tikus', '2025-12-10', '08:15:00', '11:30:00', 5),
-- Laporan untuk jadwal berulang (kunjungan 1)
(1, 3, 3, 1, 1, 'Kunjungan pertama: Fogging area parkir utama', 'Insektisida A 500ml, Fogger machine', 'Nyamuk cukup banyak di area taman', 'Perlu fogging tambahan minggu depan', '2025-12-12', '14:10:00', '15:00:00', 4);

-- =========================
-- VERIFIKASI DATA
-- =========================
SELECT '=== DATA VERIFICATION ===' as '';
SELECT 'Admin:' as Table_Name, COUNT(*) as Count FROM admin_users
UNION
SELECT 'Users:' as Table_Name, COUNT(*) as Count FROM users
UNION
SELECT 'Customers:' as Table_Name, COUNT(*) as Count FROM customers
UNION
SELECT 'Services:' as Table_Name, COUNT(*) as Count FROM services
UNION
SELECT 'Customer Services:' as Table_Name, COUNT(*) as Count FROM customer_services
UNION
SELECT 'Jadwal:' as Table_Name, COUNT(*) as Count FROM jadwal
UNION
SELECT 'Reports:' as Table_Name, COUNT(*) as Count FROM reports;

SELECT '=== SAMPLE JADWAL ===' as '';
SELECT id, kode_jadwal, tanggal, status, jenis_periode, jumlah_kunjungan, kunjungan_berjalan 
FROM jadwal 
ORDER BY tanggal;

SELECT '=== SAMPLE REPORTS ===' as '';
SELECT id, kode_laporan, tanggal_pelaporan, nomor_kunjungan, rating_customer 
FROM reports;

SELECT '=== TEST FUNCTION ===' as '';
SELECT fn_generate_kode_jadwal('2025-12-15', FALSE) as New_Jadwal_Code,
       fn_generate_kode_laporan('2025-12-15') as New_Report_Code;

COMMIT;

-- =========================
-- INFORMASI LOGIN DEFAULT
-- =========================
SELECT '=== LOGIN INFORMATION ===' as '';
SELECT 'ADMIN Login:' as Role, 'admin' as Username, 'admin123' as Password
UNION
SELECT 'PEKERJA Login:' as Role, 'budi' as Username, 'pekerja123' as Password;