# Website Pelaporan dan Rekap Data

Website sistem pelaporan dan rekap data yang dibangun dengan PHP, MySQL, dan Bootstrap. Sistem ini memungkinkan pekerja untuk membuat laporan dan admin untuk mengelola data serta mengekspor rekap dalam format PDF.

## Fitur Utama

### Untuk Pekerja/Pelapor:
- Login dengan username dan password
- Dashboard dengan statistik laporan pribadi
- Form pelaporan dengan:
  - Data diri otomatis (dari akun yang login)
  - Kategori pelaporan (dropdown)
  - Keterangan detail
  - Upload foto bukti (opsional)
  - Tanggal dan jam otomatis
- Riwayat laporan yang telah dibuat

### Untuk Admin:
- Login dengan username dan password admin
- Dashboard dengan statistik lengkap
- Kelola Laporan:
  - Lihat semua laporan
  - Detail laporan dengan modal
  - Hapus laporan
  - Pencarian dan pagination
- Kelola Pengguna (CRUD):
  - Tambah pengguna baru
  - Edit data pengguna
  - Hapus pengguna
  - Lihat daftar semua pengguna
- Kelola Kategori (CRUD):
  - Tambah kategori baru
  - Edit kategori
  - Hapus kategori (jika tidak ada laporan)
  - Lihat jumlah laporan per kategori
- Export PDF:
  - Filter berdasarkan tanggal, kategori, dan pelapor
  - Export cepat (hari ini, minggu ini, bulan ini, semua)
  - Format PDF yang rapi dengan informasi lengkap

## Struktur Database

### Tabel `users` (Pengguna/Pekerja)
- id (Primary Key)
- username (Unique)
- password (Hashed)
- nama_lengkap
- email (Unique)
- created_at, updated_at

### Tabel `admin_users` (Admin)
- id (Primary Key)
- username (Unique)
- password (Hashed)
- nama_lengkap
- email (Unique)
- created_at, updated_at

### Tabel `categories` (Kategori Pelaporan)
- id (Primary Key)
- category_name (Unique)
- description
- created_at, updated_at

### Tabel `reports` (Laporan)
- id (Primary Key)
- user_id (Foreign Key ke users)
- category_id (Foreign Key ke categories)
- keterangan
- foto_bukti (path file)
- tanggal_pelaporan
- jam_pelaporan
- created_at, updated_at

## Instalasi dan Setup

### Persyaratan Sistem
- Apache Web Server
- MySQL Database
- PHP 7.4 atau lebih baru
- Extension PHP: PDO, GD, ZIP

### Langkah Instalasi

1. **Clone atau copy project ke web server**
   ```bash
   cp -r website-pelaporan /var/www/html/
   ```

2. **Setup database**
   ```bash
   mysql -u root -p
   CREATE DATABASE website_pelaporan;
   CREATE USER 'webuser'@'localhost' IDENTIFIED BY 'webpass123';
   GRANT ALL PRIVILEGES ON website_pelaporan.* TO 'webuser'@'localhost';
   FLUSH PRIVILEGES;
   exit;
   
   mysql -u webuser -pwebpass123 website_pelaporan < database_setup.sql
   ```

3. **Set permission untuk upload folder**
   ```bash
   chmod -R 777 /var/www/html/website-pelaporan/assets/uploads
   ```

4. **Konfigurasi database** (jika diperlukan)
   Edit file `config/database.php` sesuai dengan setting database Anda.

## Akun Default

### Admin
- Username: `admin`
- Password: `password`

### Pekerja
- Username: `pekerja1` / Password: `password`
- Username: `pekerja2` / Password: `password`

## Kategori Default
- Keamanan
- Kebersihan
- Fasilitas
- Lainnya

## Teknologi yang Digunakan

- **Backend**: PHP dengan PDO untuk database
- **Database**: MySQL
- **Frontend**: HTML, CSS, JavaScript, Bootstrap 5
- **PDF Generation**: TCPDF Library
- **Icons**: Font Awesome
- **Security**: Password hashing dengan PHP password_hash()

## Struktur File

```
website-pelaporan/
├── admin/                  # Halaman admin
│   ├── dashboard.php
│   ├── reports.php
│   ├── users.php
│   ├── categories.php
│   └── export_pdf.php
├── user/                   # Halaman user/pekerja
│   ├── dashboard.php
│   └── create_report.php
├── assets/                 # Asset static
│   ├── css/
│   ├── js/
│   └── uploads/           # Folder upload foto
├── config/                 # Konfigurasi
│   └── database.php
├── includes/               # File include
│   └── functions.php
├── tcpdf/                 # Library PDF
├── database_setup.sql     # Script setup database
├── login.php             # Halaman login
├── logout.php            # Logout handler
└── index.php             # Landing page
```

## Keamanan

- Password di-hash menggunakan PHP `password_hash()`
- Input sanitization untuk mencegah XSS
- Prepared statements untuk mencegah SQL injection
- Session management untuk autentikasi
- File upload validation untuk keamanan

## Fitur Tambahan

- Responsive design (mobile-friendly)
- Real-time clock update pada form
- Image preview sebelum upload
- Pagination untuk data besar
- Search functionality
- Modal untuk detail laporan
- Alert notifications
- Auto-logout handling

## Support

Untuk pertanyaan atau bantuan, silakan hubungi administrator sistem.

