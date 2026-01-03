-- ============================================
-- DATABASE: website_pelaporan (Pest Control System)
-- VERSION: 2.0 (With Station Inspection System)
-- ============================================

DROP DATABASE IF EXISTS website_pelaporan;
CREATE DATABASE website_pelaporan 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_general_ci;

USE website_pelaporan;

-- ============================================
-- TABEL: admin_users
-- ============================================
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

-- Admin default
INSERT INTO admin_users (nama, username, password, telepon) VALUES
('Administrator Sistem', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '081234567890');
-- Password: admin123

-- ============================================
-- TABEL: users (PEKERJA)
-- ============================================
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

INSERT INTO users (nama, username, email, password, telepon, jabatan) VALUES
('Budi Pekerja', 'budi', 'budi@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081298765432', 'Teknisi Pest Control'),
('Sari Teknisi', 'sari', 'sari@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081387654321', 'Teknisi Pest Control'),
('Anto Supervisor', 'anto', 'anto@example.com', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', '081456789012', 'Supervisor Lapangan');
-- Password: pekerja123

-- ============================================
-- TABEL: services
-- ============================================
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

INSERT INTO services (kode_service, nama_service, deskripsi, durasi_menit, harga, kategori) VALUES
('FOG-001', 'Fogging Nyamuk', 'Pengasapan area outdoor/indoor untuk pengendalian nyamuk dan serangga terbang', 60, 350000.00, 'Residential'),
('FUM-001', 'Fumigasi Gudang', 'Pengendalian hama gudang komprehensif untuk area penyimpanan', 180, 1500000.00, 'Industrial'),
('SPR-001', 'Penyemprotan Rumah', 'Insektisida aman residensial untuk indoor treatment', 45, 200000.00, 'Residential'),
('ROD-001', 'Rodent Control', 'Pengendalian tikus dan rodent lainnya dengan sistem monitoring', 90, 750000.00, 'Commercial'),
('TER-001', 'Termite Control', 'Pengendalian rayap dengan sistem baiting dan chemical barrier', 120, 1200000.00, 'Commercial'),
('SAN-001', 'Sanitasi Area', 'Pembersihan dan sanitasi area dari kontaminasi hama', 120, 800000.00, 'Commercial');

-- ============================================
-- TABEL: customers (DENGAN STATION INSPEKSI)
-- ============================================
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
  jumlah_station INT DEFAULT 0 COMMENT 'Jumlah station inspeksi untuk customer',
  keterangan TEXT,
  
  -- Informasi kontrak
  tanggal_mulai_kontrak DATE,
  tanggal_selesai_kontrak DATE,
  nilai_kontrak DECIMAL(15,2),
  status_kontrak ENUM('Aktif', 'Selesai', 'Ditangguhkan', 'Dibatalkan') DEFAULT 'Aktif',
  
  status ENUM('Aktif', 'Nonaktif', 'Trial') DEFAULT 'Aktif',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  INDEX idx_status (status),
  INDEX idx_nama (nama_perusahaan, nama_customer),
  INDEX idx_kontrak (status_kontrak),
  INDEX idx_jumlah_station (jumlah_station)
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
  jumlah_station,
  tanggal_mulai_kontrak,
  tanggal_selesai_kontrak,
  nilai_kontrak, 
  status_kontrak,
  keterangan,
  status
) VALUES
('PT. Industri Maju Jaya', 'Bapak Joko Susanto', '02112345678', 'joko@industrimaju.com', 
 'Jl. Industri Raya No.45 Jakarta Barat', 'Gedung Utama', 'Lantai 1', 'Area A-B', 8,
 '2024-01-01', '2025-12-31', 50000000.00, 'Aktif', 
 'Gudang produksi, butuh fumigasi rutin bulanan. Kontrak platinum - include emergency service', 'Aktif'),

('Ibu Siti Rahayu', 'Ibu Siti Rahayu', '02156789012', 'siti.rahayu@gmail.com', 
 'Apartemen Taman Anggrek', 'Tower B', 'Lantai 8', 'Unit 801', 1,
 '2024-07-01', '2024-12-31', 2400000.00, 'Aktif', 
 'Semprot bulanan untuk apartemen 3BR. Paket silver - residential', 'Aktif'),

('PT. Bangun Sejahtera', 'Bapak Rudi Hartono', '02134567890', 'rudi@bangunsejahtera.co.id', 
 'Komplek Perkantoran Sudirman', 'Tower 2', 'Lantai 15', 'Suite 1501-1505', 5,
 '2024-10-01', '2024-12-31', 3150000.00, 'Aktif', 
 'Fogging area parkir dan taman kantor. Trial period - evaluasi setelah 3 bulan', 'Trial'),

('PT. Retail Indonesia', 'Ibu Maya Sari', '02198765432', 'maya@retailindo.co.id', 
 'Mal Mega Kuningan', 'Mall Main Building', 'Lantai 3', 'Food Court Area', 12,
 '2024-06-01', '2025-05-31', 10800000.00, 'Aktif', 
 'Monitoring tikus area food court. Kontrak bulanan untuk rodent control', 'Aktif'),

('RS. Sehat Sentosa', 'Dr. Andi Wijaya', '02187654321', 'andi.wijaya@rsss.com', 
 'Jl. Kesehatan No. 123', 'Gedung Utama', 'Lantai Basement & 2', 'Gudang Farmasi & Ruang Operasi', 10,
 '2024-01-01', '2024-12-31', 15000000.00, 'Aktif', 
 'Pengendalian rayap di area kritis rumah sakit. Kontrak tahunan termite control - priority service', 'Aktif'),

('PT. Hotel Grand Indonesia', 'Bapak Agus Santoso', '02111122233', 'agus@grandhotel.com', 
 'Jl. MH Thamrin No.1', 'Main Building', 'Lantai 1-10', 'Semua Area', 15,
 '2024-03-01', '2025-02-28', 20000000.00, 'Aktif', 
 'Hotel 5-star, butuh comprehensive pest control semua area', 'Aktif');

-- ============================================
-- TABEL: customer_services
-- ============================================
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
  INDEX idx_customer_service (customer_id, service_id),
  INDEX idx_status (status)
);

INSERT INTO customer_services (customer_id, service_id, tanggal_mulai, tanggal_selesai, nilai_kontrak, status, keterangan) VALUES
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
(5, 3, '2024-01-01', '2024-12-31', 3000000.00, 'Aktif', 'Penyemprotan ruang operasi'),

-- Customer 6 punya 3 layanan
(6, 1, '2024-03-01', '2025-02-28', 8000000.00, 'Aktif', 'Fogging area outdoor hotel'),
(6, 3, '2024-03-01', '2025-02-28', 8000000.00, 'Aktif', 'Penyemprotan kamar & koridor'),
(6, 6, '2024-03-01', '2025-02-28', 4000000.00, 'Aktif', 'Sanitasi area dapur');

-- ============================================
-- TABEL: stations (TABEL BARU UNTUK STATION DETAIL)
-- ============================================
DROP TABLE IF EXISTS stations;
CREATE TABLE stations (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  station_number INT NOT NULL,
  nama_station VARCHAR(100) NOT NULL,
  lokasi TEXT,
  deskripsi TEXT,
  foto_sebelum VARCHAR(255),
  foto_sesudah VARCHAR(255),
  status ENUM('Aktif', 'Nonaktif', 'Diperbaiki') DEFAULT 'Aktif',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  UNIQUE KEY unique_customer_station (customer_id, station_number),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_customer (customer_id),
  INDEX idx_station_number (station_number),
  INDEX idx_status (status)
);

-- Insert contoh station untuk customer 1 (8 station)
INSERT INTO stations (customer_id, station_number, nama_station, lokasi, deskripsi) VALUES
(1, 1, 'Gudang A - Area Loading', 'Gedung Utama, Lantai 1, Area Loading Dock', 'Area penerimaan barang, sering ditemui tikus'),
(1, 2, 'Gudang A - Rack Storage 1-10', 'Gedung Utama, Lantai 1, Rack 1-10', 'Area penyimpanan bahan baku'),
(1, 3, 'Gudang A - Rack Storage 11-20', 'Gedung Utama, Lantai 1, Rack 11-20', 'Area penyimpanan produk jadi'),
(1, 4, 'Gudang B - Cold Storage', 'Gedung Utama, Lantai 1, Cold Storage Room', 'Ruangan penyimpanan dingin'),
(1, 5, 'Area Parkir Kendaraan', 'Area Parkir Belakang', 'Parkir kendaraan pengangkut'),
(1, 6, 'Kantin Karyawan', 'Gedung Utama, Lantai 1, Kantin', 'Area makan karyawan'),
(1, 7, 'Toilet Umum', 'Gedung Utama, Lantai 1, Toilet', 'Toilet umum karyawan'),
(1, 8, 'Area Pembuangan Sampah', 'Belakang Gedung', 'Tempat pembuangan sementara');

-- Insert station untuk customer 4 (mall - 12 station)
INSERT INTO stations (customer_id, station_number, nama_station, lokasi, deskripsi) VALUES
(4, 1, 'Food Court - Area Dapur 1', 'Lantai 3, Food Court, Dapur Tenant 1', 'Dapur tenant makanan cepat saji'),
(4, 2, 'Food Court - Area Dapur 2', 'Lantai 3, Food Court, Dapur Tenant 2', 'Dapur tenant makanan tradisional'),
(4, 3, 'Food Court - Tempat Sampah', 'Lantai 3, Food Court, Area Sampah', 'Tempat penampungan sampah sementara'),
(4, 4, 'Food Court - Storage Bahan', 'Lantai 3, Food Court, Ruang Storage', 'Penyimpanan bahan makanan'),
(4, 5, 'Cinema - Lobby', 'Lantai 4, Cinema Lobby', 'Area lobby bioskop'),
(4, 6, 'Cinema - Koridor', 'Lantai 4, Cinema Koridor', 'Koridor menuju ruang bioskop'),
(4, 7, 'Parkir Basement - Area A', 'Basement, Area Parkir A', 'Area parkir kendaraan'),
(4, 8, 'Parkir Basement - Area B', 'Basement, Area Parkir B', 'Area parkir motor'),
(4, 9, 'Supermarket - Area Sayur', 'Lantai 2, Supermarket, Area Sayur', 'Penjualan sayuran segar'),
(4, 10, 'Supermarket - Area Daging', 'Lantai 2, Supermarket, Area Daging', 'Penjualan daging dan ikan'),
(4, 11, 'Toilet Umum - Lantai 3', 'Lantai 3, Toilet Umum', 'Toilet umum pengunjung'),
(4, 12, 'Ruang Teknis', 'Lantai 5, Ruang Teknis', 'Ruang peralatan teknis mall');

-- ============================================
-- TABEL: jadwal (DENGAN STATION SUPPORT)
-- ============================================
DROP TABLE IF EXISTS jadwal;
CREATE TABLE jadwal (
  id INT NOT NULL AUTO_INCREMENT,
  kode_jadwal VARCHAR(50) UNIQUE,
  
  -- Informasi penugasan
  admin_id INT NOT NULL,
  pekerja_id INT,
  customer_id INT NOT NULL,
  service_id INT NOT NULL,
  
  -- Waktu pelaksanaan
  tanggal DATE NOT NULL,
  jam TIME NOT NULL,
  lokasi TEXT,
  durasi_estimasi INT,
  
  -- Status dan prioritas
  status ENUM('Menunggu','Berjalan','Selesai','Dibatalkan') DEFAULT 'Menunggu',
  prioritas ENUM('Rendah', 'Sedang', 'Tinggi', 'Darurat') DEFAULT 'Sedang',
  
  -- Catatan
  catatan_admin TEXT,
  catatan_pekerja TEXT,
  
  -- Informasi periode (untuk jadwal berulang)
  jenis_periode ENUM('Sekali', 'Harian', 'Mingguan', 'Bulanan', 'Tahunan') DEFAULT 'Sekali',
  jumlah_kunjungan INT DEFAULT 1,
  kunjungan_berjalan INT DEFAULT 0,
  tanggal_selesai_periode DATE,
  
  -- Parent-child relationship untuk jadwal berulang
  parent_jadwal_id INT NULL,
  
  -- Station tracking
  station_terakhir INT DEFAULT 0 COMMENT 'Station terakhir yang dilaporkan',
  total_station_selesai INT DEFAULT 0 COMMENT 'Total station yang sudah selesai',
  
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  INDEX idx_tanggal (tanggal),
  INDEX idx_status (status),
  INDEX idx_pekerja (pekerja_id),
  INDEX idx_customer (customer_id),
  INDEX idx_parent_jadwal (parent_jadwal_id),
  INDEX idx_jenis_periode (jenis_periode),
  INDEX idx_station_tracking (station_terakhir, total_station_selesai),
  
  FOREIGN KEY (pekerja_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_jadwal_id) REFERENCES jadwal(id) ON DELETE SET NULL,
  CONSTRAINT fk_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
);

-- ============================================
-- TABEL: reports (DENGAN STATION SUPPORT)
-- ============================================
DROP TABLE IF EXISTS reports;
CREATE TABLE reports (
  id INT NOT NULL AUTO_INCREMENT,
  kode_laporan VARCHAR(50) UNIQUE,
  
  -- Informasi pelaporan
  user_id INT NOT NULL,
  jadwal_id INT,
  customer_id INT,
  service_id INT,
  station_id INT DEFAULT NULL COMMENT 'Nomor station yang dilaporkan',
  station_nama VARCHAR(100) DEFAULT NULL COMMENT 'Nama station (jika ada)',
  nomor_kunjungan INT DEFAULT 1,
  
  -- Isi laporan
  keterangan TEXT NOT NULL,
  bahan_digunakan TEXT,
  hasil_pengamatan TEXT,
  rekomendasi TEXT,
  
  -- Bukti foto
  foto_bukti VARCHAR(255),
  foto_sebelum VARCHAR(255),
  foto_sesudah VARCHAR(255),
  
  -- Waktu pelaporan
  tanggal_pelaporan DATE NOT NULL,
  jam_mulai TIME,
  jam_selesai TIME,
  
  -- Rating dan feedback
  rating_customer INT DEFAULT 5 CHECK (rating_customer BETWEEN 1 AND 5),
  
  -- Metadata
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  
  -- Indexes
  INDEX idx_user (user_id),
  INDEX idx_tanggal (tanggal_pelaporan),
  INDEX idx_jadwal (jadwal_id),
  INDEX idx_customer (customer_id),
  INDEX idx_service (service_id),
  INDEX idx_nomor_kunjungan (nomor_kunjungan),
  INDEX idx_station (station_id, jadwal_id),
  INDEX idx_rating (rating_customer),
  
  -- Unique constraints
  UNIQUE KEY unique_jadwal_kunjungan (jadwal_id, nomor_kunjungan),
  UNIQUE KEY unique_jadwal_station (jadwal_id, station_id),
  
  -- Foreign keys
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (jadwal_id) REFERENCES jadwal(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
);

-- ============================================
-- FUNGSI: Generate Kode
-- ============================================
DELIMITER //

-- Fungsi generate kode jadwal
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

-- Fungsi generate kode laporan
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

-- ============================================
-- TRIGGER: Auto Generate Kode
-- ============================================
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
    
    -- Jika jadwal sekali dan customer punya station, set jumlah_kunjungan
    IF NEW.jenis_periode = 'Sekali' THEN
        SELECT jumlah_station INTO @station_count 
        FROM customers 
        WHERE id = NEW.customer_id;
        
        IF @station_count > 0 THEN
            SET NEW.jumlah_kunjungan = @station_count;
        END IF;
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
    
    -- Set station_nama jika station_id ada tapi station_nama kosong
    IF NEW.station_id IS NOT NULL AND NEW.station_nama IS NULL THEN
        SELECT nama_station INTO @station_name
        FROM stations 
        WHERE customer_id = NEW.customer_id 
          AND station_number = NEW.station_id
        LIMIT 1;
        
        IF @station_name IS NOT NULL THEN
            SET NEW.station_nama = @station_name;
        ELSE
            SET NEW.station_nama = CONCAT('Station #', NEW.station_id);
        END IF;
    END IF;
END //

-- Trigger untuk update tracking station setelah laporan dibuat
CREATE TRIGGER tr_after_report_insert
AFTER INSERT ON reports
FOR EACH ROW
BEGIN
    -- Update kunjungan_berjalan di jadwal
    IF NEW.jadwal_id IS NOT NULL THEN
        -- Update progress kunjungan
        UPDATE jadwal 
        SET kunjungan_berjalan = (
            SELECT COALESCE(MAX(nomor_kunjungan), 0)
            FROM reports 
            WHERE jadwal_id = NEW.jadwal_id
        ),
        updated_at = NOW()
        WHERE id = NEW.jadwal_id;
        
        -- Update station tracking jika ada station_id
        IF NEW.station_id IS NOT NULL THEN
            UPDATE jadwal 
            SET station_terakhir = NEW.station_id,
                total_station_selesai = (
                    SELECT COUNT(DISTINCT station_id)
                    FROM reports 
                    WHERE jadwal_id = NEW.jadwal_id 
                    AND station_id IS NOT NULL
                ),
                updated_at = NOW()
            WHERE id = NEW.jadwal_id;
        END IF;
        
        -- Update status jadi Selesai jika semua kunjungan sudah dilaporkan
        UPDATE jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        SET j.status = 'Selesai',
            j.updated_at = NOW()
        WHERE j.id = NEW.jadwal_id
        AND (
            -- Untuk jadwal berulang: semua kunjungan selesai
            (j.jenis_periode != 'Sekali' AND j.kunjungan_berjalan >= j.jumlah_kunjungan)
            OR
            -- Untuk jadwal sekali dengan station: semua station selesai
            (j.jenis_periode = 'Sekali' AND c.jumlah_station > 0 
             AND j.total_station_selesai >= c.jumlah_station)
            OR
            -- Untuk jadwal sekali tanpa station: 1 kunjungan selesai
            (j.jenis_periode = 'Sekali' AND c.jumlah_station = 0 
             AND j.kunjungan_berjalan >= 1)
        );
    END IF;
END //

DELIMITER ;

-- ============================================
-- VIEW: Dashboard dan Laporan
-- ============================================

-- View untuk dashboard pekerja
DROP VIEW IF EXISTS view_dashboard_pekerja;
CREATE VIEW view_dashboard_pekerja AS
SELECT 
    u.id as user_id,
    u.nama as nama_pekerja,
    u.jabatan,
    
    -- Statistik
    COUNT(DISTINCT r.id) as total_laporan,
    COUNT(DISTINCT CASE WHEN DATE(r.tanggal_pelaporan) = CURDATE() THEN r.id END) as laporan_hari_ini,
    COUNT(DISTINCT j.id) as total_jadwal,
    COUNT(DISTINCT CASE WHEN j.tanggal = CURDATE() AND j.status IN ('Menunggu', 'Berjalan') THEN j.id END) as jadwal_hari_ini,
    COUNT(DISTINCT CASE WHEN j.status = 'Selesai' THEN j.id END) as jadwal_selesai,
    COUNT(DISTINCT c.id) as total_customer,
    
    -- Station progress
    SUM(CASE WHEN j.jenis_periode = 'Sekali' AND c.jumlah_station > 0 
             THEN j.total_station_selesai ELSE 0 END) as total_station_selesai,
    SUM(CASE WHEN j.jenis_periode = 'Sekali' AND c.jumlah_station > 0 
             THEN c.jumlah_station ELSE 0 END) as total_station_ditugaskan
    
FROM users u
LEFT JOIN reports r ON u.id = r.user_id
LEFT JOIN jadwal j ON u.id = j.pekerja_id
LEFT JOIN customers c ON j.customer_id = c.id
GROUP BY u.id, u.nama, u.jabatan;

-- View untuk customer dengan layanan dan station
DROP VIEW IF EXISTS view_customer_services;
CREATE VIEW view_customer_services AS
SELECT 
    c.id as customer_id,
    c.nama_perusahaan,
    c.nama_customer,
    c.telepon,
    c.email,
    c.jumlah_station,
    c.status as customer_status,
    c.status_kontrak,
    
    -- Layanan
    GROUP_CONCAT(DISTINCT CONCAT(s.nama_service, ' (', s.kode_service, ')') SEPARATOR ', ') as services_list,
    GROUP_CONCAT(DISTINCT s.id) as service_ids,
    COUNT(DISTINCT cs.id) as total_services,
    
    -- Station
    (SELECT COUNT(*) FROM stations st WHERE st.customer_id = c.id) as station_terdaftar,
    
    -- Kontrak
    MIN(cs.tanggal_mulai) as kontrak_mulai,
    MAX(cs.tanggal_selesai) as kontrak_selesai,
    SUM(cs.nilai_kontrak) as total_nilai_kontrak
    
FROM customers c
LEFT JOIN customer_services cs ON c.id = cs.customer_id
LEFT JOIN services s ON cs.service_id = s.id
GROUP BY c.id;

-- View untuk jadwal dengan informasi lengkap + station progress
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
    c.jumlah_station,
    s.nama_service,
    s.kode_service,
    s.harga,
    s.durasi_menit,
    s.kategori,
    u.nama as pekerja_nama,
    u.jabatan as pekerja_jabatan,
    u.telepon as pekerja_telepon,
    a.nama as admin_nama,
    
    -- Progress tracking
    (SELECT COUNT(*) FROM reports r WHERE r.jadwal_id = j.id) as total_laporan,
    (SELECT MAX(r.nomor_kunjungan) FROM reports r WHERE r.jadwal_id = j.id) as last_reported_kunjungan,
    
    -- Station progress
    CASE 
        WHEN j.jenis_periode = 'Sekali' AND c.jumlah_station > 0 
        THEN CONCAT(j.total_station_selesai, '/', c.jumlah_station, ' station')
        WHEN j.jenis_periode = 'Sekali' 
        THEN 'Sekali'
        WHEN j.kunjungan_berjalan >= j.jumlah_kunjungan 
        THEN 'Selesai Semua'
        ELSE CONCAT(j.kunjungan_berjalan, '/', j.jumlah_kunjungan, ' kunjungan')
    END as progress_status,
    
    -- Station info
    CASE 
        WHEN j.jenis_periode = 'Sekali' AND c.jumlah_station > 0 
        THEN 'Station Inspeksi'
        WHEN j.jenis_periode = 'Sekali' 
        THEN 'Single Visit'
        ELSE 'Recurring Schedule'
    END as schedule_type
    
FROM jadwal j
LEFT JOIN customers c ON j.customer_id = c.id
LEFT JOIN services s ON j.service_id = s.id
LEFT JOIN users u ON j.pekerja_id = u.id
LEFT JOIN admin_users a ON j.admin_id = a.id;

-- View untuk laporan dengan station info
DROP VIEW IF EXISTS view_reports_detail;
CREATE VIEW view_reports_detail AS
SELECT 
    r.*,
    u.nama as pekerja_nama,
    u.jabatan as pekerja_jabatan,
    c.nama_perusahaan,
    c.nama_customer,
    c.telepon as customer_telepon,
    s.nama_service,
    s.kode_service,
    j.kode_jadwal,
    j.tanggal as jadwal_tanggal,
    j.jam as jadwal_jam,
    
    -- Station info
    st.nama_station as station_detail_nama,
    st.lokasi as station_lokasi,
    
    -- Calculated fields
    TIMEDIFF(r.jam_selesai, r.jam_mulai) as durasi_pekerjaan,
    CASE 
        WHEN r.station_id IS NOT NULL 
        THEN CONCAT('Station #', r.station_id, ' - ', COALESCE(r.station_nama, st.nama_station, 'Tidak ada nama'))
        ELSE 'Tanpa Station'
    END as station_display
    
FROM reports r
LEFT JOIN users u ON r.user_id = u.id
LEFT JOIN customers c ON r.customer_id = c.id
LEFT JOIN services s ON r.service_id = s.id
LEFT JOIN jadwal j ON r.jadwal_id = j.id
LEFT JOIN stations st ON r.customer_id = st.customer_id AND r.station_id = st.station_number;

-- ============================================
-- INDEX Tambahan
-- ============================================
CREATE INDEX idx_reports_tanggal_user ON reports(tanggal_pelaporan, user_id);
CREATE INDEX idx_jadwal_tanggal_status ON jadwal(tanggal, status);
CREATE INDEX idx_customers_nama ON customers(nama_perusahaan, nama_customer);
CREATE INDEX idx_customers_kontrak ON customers(tanggal_selesai_kontrak, status_kontrak);
CREATE INDEX idx_customer_services_customer ON customer_services(customer_id);
CREATE INDEX idx_customer_services_service ON customer_services(service_id);
CREATE INDEX idx_reports_jadwal_kunjungan ON reports(jadwal_id, nomor_kunjungan);
CREATE INDEX idx_jadwal_parent ON jadwal(parent_jadwal_id);
CREATE INDEX idx_reports_station_composite ON reports(jadwal_id, station_id);
CREATE INDEX idx_stations_customer_number ON stations(customer_id, station_number);

-- ============================================
-- INSERT DATA SAMPLE JADWAL DAN LAPORAN
-- ============================================

-- Insert sample jadwal
INSERT INTO jadwal (admin_id, pekerja_id, customer_id, service_id, tanggal, jam, lokasi, 
                   durasi_estimasi, status, prioritas, catatan_admin, jenis_periode, 
                   jumlah_kunjungan, kunjungan_berjalan, station_terakhir, total_station_selesai)
VALUES
-- Jadwal sekali dengan station (customer 1)
(1, 1, 1, 2, '2025-12-10', '08:00:00', 'Gudang A Loading Dock, Lantai 1', 180, 'Berjalan', 'Tinggi', 
 'Fumigasi gudang - mulai dari station 1', 'Sekali', 8, 3, 3, 3),

-- Jadwal sekali tanpa station (customer 2)
(1, 2, 2, 3, '2025-12-11', '09:30:00', 'Apartemen Tower B Lt.8 Unit 801', 45, 'Menunggu', 'Sedang', 
 'Semprot rumah rutin - customer sensitif aroma', 'Sekali', 1, 0, 0, 0),

-- Jadwal berulang bulanan
(1, 1, 3, 1, '2025-12-12', '14:00:00', 'Parkir basement dan taman kantor, Tower 2', 60, 'Berjalan', 'Sedang', 
 'Fogging area outdoor - koordinasi dengan security', 'Bulanan', 3, 1, 0, 0),

-- Jadwal sekali dengan station banyak (customer 4 - mall)
(1, 1, 4, 4, '2025-12-13', '10:00:00', 'Food Court Area, Lantai 3 Mall', 120, 'Menunggu', 'Tinggi', 
 'Rodent control - mulai dari area food court', 'Sekali', 12, 0, 0, 0);

-- Insert sample reports dengan station
INSERT INTO reports (user_id, jadwal_id, customer_id, service_id, station_id, station_nama, 
                    nomor_kunjungan, keterangan, bahan_digunakan, hasil_pengamatan, rekomendasi,
                    tanggal_pelaporan, jam_mulai, jam_selesai, rating_customer)
VALUES
-- Laporan untuk jadwal 1, station 1
(1, 1, 1, 2, 1, 'Gudang A - Area Loading', 1, 
 'Pemeriksaan area loading dock, ditemui jejak tikus di sudut timur',
 'Rodent bait station 4 unit, monitoring glue board 6 unit',
 'Jejak tikus ditemukan di 3 titik, aktivitas sedang',
 'Pasang bait station tambahan di area sampah', 
 '2025-12-10', '08:15:00', '09:30:00', 4),

-- Laporan untuk jadwal 1, station 2
(1, 1, 1, 2, 2, 'Gudang A - Rack Storage 1-10', 2,
 'Pemeriksaan rack storage 1-10, kondisi bersih',
 'Fogging insecticide 500ml, sprayer 1 unit',
 'Tidak ditemukan aktivitas hama, kondisi storage baik',
 'Lanjutkan monitoring rutin',
 '2025-12-10', '09:45:00', '10:30:00', 5),

-- Laporan untuk jadwal 1, station 3
(1, 1, 1, 2, 3, 'Gudang A - Rack Storage 11-20', 3,
 'Pemeriksaan rack 11-20, ditemui jejak kecoa',
 'Cockroach gel bait 20g, insecticide spray 200ml',
 'Aktivitas kecoa rendah, ditemui di 2 titik',
 'Aplikasi ulang gel bait setelah 2 minggu',
 '2025-12-10', '10:45:00', '11:30:00', 4),

-- Laporan untuk jadwal 3 (berulang)
(1, 3, 3, 1, NULL, NULL, 1,
 'Fogging area parkir kantor',
 'Fogging machine 1 unit, insecticide 1L',
 'Nyamuk cukup banyak di area taman dekat kolam',
 'Bersihkan genangan air di area taman',
 '2025-12-12', '14:10:00', '15:00:00', 4);

-- ============================================
-- PROCEDURE: Update Station Progress
-- ============================================
DELIMITER //

CREATE PROCEDURE sp_update_station_progress(
    IN p_jadwal_id INT
)
BEGIN
    DECLARE v_total_station INT;
    DECLARE v_station_selesai INT;
    DECLARE v_station_terakhir INT;
    
    -- Ambil jumlah station dari customer
    SELECT c.jumlah_station INTO v_total_station
    FROM jadwal j
    JOIN customers c ON j.customer_id = c.id
    WHERE j.id = p_jadwal_id;
    
    -- Hitung station yang sudah selesai
    SELECT COUNT(DISTINCT station_id) INTO v_station_selesai
    FROM reports
    WHERE jadwal_id = p_jadwal_id 
      AND station_id IS NOT NULL;
    
    -- Ambil station terakhir yang dilaporkan
    SELECT MAX(station_id) INTO v_station_terakhir
    FROM reports
    WHERE jadwal_id = p_jadwal_id 
      AND station_id IS NOT NULL;
    
    -- Update jadwal
    UPDATE jadwal
    SET total_station_selesai = v_station_selesai,
        station_terakhir = COALESCE(v_station_terakhir, 0),
        updated_at = NOW()
    WHERE id = p_jadwal_id;
    
    -- Update status jika semua station selesai
    IF v_total_station > 0 AND v_station_selesai >= v_total_station THEN
        UPDATE jadwal
        SET status = 'Selesai',
            updated_at = NOW()
        WHERE id = p_jadwal_id;
    END IF;
    
    SELECT 'Progress updated successfully' as message,
           v_station_selesai as station_completed,
           v_total_station as total_station,
           v_station_terakhir as last_station;
END //

DELIMITER ;

-- ============================================
-- VERIFIKASI DATA
-- ============================================
SELECT '=== DATABASE STRUCTURE VERIFICATION ===' as '';
SELECT 'Admin:' as Table_Name, COUNT(*) as Count FROM admin_users
UNION
SELECT 'Users:' as Table_Name, COUNT(*) as Count FROM users
UNION
SELECT 'Customers:' as Table_Name, COUNT(*) as Count FROM customers
UNION
SELECT 'Services:' as Table_Name, COUNT(*) as Count FROM services
UNION
SELECT 'Stations:' as Table_Name, COUNT(*) as Count FROM stations
UNION
SELECT 'Customer Services:' as Table_Name, COUNT(*) as Count FROM customer_services
UNION
SELECT 'Jadwal:' as Table_Name, COUNT(*) as Count FROM jadwal
UNION
SELECT 'Reports:' as Table_Name, COUNT(*) as Count FROM reports;

SELECT '=== CUSTOMER STATION SUMMARY ===' as '';
SELECT 
    c.nama_perusahaan,
    c.jumlah_station,
    COUNT(s.id) as station_terdaftar,
    (SELECT COUNT(DISTINCT r.station_id) 
     FROM reports r 
     WHERE r.customer_id = c.id) as station_dilaporkan
FROM customers c
LEFT JOIN stations s ON c.id = s.customer_id
GROUP BY c.id
ORDER BY c.nama_perusahaan;

SELECT '=== JADWAL PROGRESS ===' as '';
SELECT 
    j.kode_jadwal,
    c.nama_perusahaan,
    j.jenis_periode,
    j.status,
    j.kunjungan_berjalan,
    j.jumlah_kunjungan,
    j.total_station_selesai,
    c.jumlah_station,
    CASE 
        WHEN j.jenis_periode = 'Sekali' AND c.jumlah_station > 0 
        THEN CONCAT(ROUND((j.total_station_selesai / c.jumlah_station) * 100, 1), '%')
        WHEN j.jenis_periode != 'Sekali'
        THEN CONCAT(ROUND((j.kunjungan_berjalan / j.jumlah_kunjungan) * 100, 1), '%')
        ELSE '100%'
    END as progress_percentage
FROM jadwal j
JOIN customers c ON j.customer_id = c.id
ORDER BY j.tanggal;

SELECT '=== SAMPLE REPORTS WITH STATION ===' as '';
SELECT 
    r.kode_laporan,
    c.nama_perusahaan,
    s.nama_service,
    r.station_id,
    r.station_nama,
    r.tanggal_pelaporan,
    r.rating_customer
FROM reports r
JOIN customers c ON r.customer_id = c.id
JOIN services s ON r.service_id = s.id
WHERE r.station_id IS NOT NULL
ORDER BY r.tanggal_pelaporan DESC
LIMIT 5;

SELECT '=== TEST FUNCTION ===' as '';
SELECT fn_generate_kode_jadwal('2025-12-15', FALSE) as New_Jadwal_Code,
       fn_generate_kode_laporan('2025-12-15') as New_Report_Code;

-- ============================================
-- INFORMASI LOGIN DEFAULT
-- ============================================
SELECT '=== LOGIN INFORMATION ===' as '';
SELECT 'ADMIN Login:' as Role, 'admin' as Username, 'admin123' as Password
UNION
SELECT 'PEKERJA Login:' as Role, 'budi' as Username, 'pekerja123' as Password
UNION
SELECT 'PEKERJA Login:' as Role, 'sari' as Username, 'pekerja123' as Password
UNION
SELECT 'PEKERJA Login:' as Role, 'anto' as Username, 'pekerja123' as Password;

COMMIT;
