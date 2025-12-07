# 🐜 Sistem Manajemen Pest Control 

Sistem manajemen pekerjaan pest control terintegrasi untuk mengelola jadwal, laporan, dan administrasi layanan pembasmi hama profesional.

![Dashboard Preview](assets/img/dashboard-preview.png)

## 🚀 Fitur Utama

### 👷 Untuk Pekerja (Teknisi Lapangan)
- ✅ **Dashboard Personal** - Statistik pekerjaan pribadi
- ✅ **Jadwal Saya** - Lihat dan filter jadwal pekerjaan
- ✅ **Buat Laporan** - Form laporan dengan foto bukti
- ✅ **Laporan Saya** - Riwayat laporan yang telah dibuat
- ✅ **Profil** - Kelola data pribadi

### 👨‍💼 Untuk Admin (Supervisor/Manager)
- ✅ **Dashboard Admin** - Statistik lengkap perusahaan
- ✅ **Manajemen Pelanggan** - CRUD data customer
- ✅ **Manajemen Layanan** - CRUD jenis layanan pest control
- ✅ **Manajemen Pekerja** - Kelola data teknisi
- ✅ **Manajemen Jadwal** - Atur jadwal pekerjaan
- ✅ **Monitoring Laporan** - Pantau semua laporan
- ✅ **Export Data** - PDF & Excel dengan filter
- ✅ **Analytics** - Grafik dan statistik performa

## 📋 Persyaratan Sistem

### Minimum Requirements:
- **Web Server**: Apache 2.4+ / Nginx 1.18+
- **Database**: MySQL 5.7+ atau MariaDB 10.2+
- **PHP**: 7.4 atau lebih baru
- **Memory**: Minimum 512MB RAM
- **Storage**: 500MB free space

### PHP Extensions Required:
```bash
sudo apt-get install php7.4-mysql php7.4-gd php7.4-zip php7.4-mbstring php7.4-xml php7.4-curl
pest-control/
├── admin/                      # Halaman admin
│   ├── dashboard.php          # Dashboard admin
│   ├── customers.php          # Manajemen pelanggan
│   ├── services.php           # Manajemen layanan
│   ├── workers.php            # Manajemen pekerja
│   ├── schedules.php          # Manajemen jadwal
│   ├── reports.php            # Monitoring laporan
│   └── generate_pdf.php       # Export PDF/Excel
├── user/                      # Halaman pekerja
│   ├── dashboard.php          # Dashboard pekerja
│   ├── my_schedule.php        # Jadwal saya
│   ├── my_reports.php         # Laporan saya
│   ├── create_report.php      # Buat laporan baru
│   └── profile.php            # Profil saya
├── assets/                    # Assets statis
│   ├── css/                  # Stylesheet custom
│   ├── js/                   # JavaScript custom
│   ├── img/                  # Images & icons
│   └── uploads/              # Folder upload foto
├── includes/                  # File include
│   ├── functions.php         # Helper functions
│   ├── auth.php             # Authentication functions
│   └── database.php         # Database connection
├── config/                   # Konfigurasi
│   └── database.php         # Database config
├── tcpdf/                    # TCPDF library
├── docs/                     # Dokumentasi
├── init.sql                  # Database schema
├── login.php                # Login page
├── logout.php               # Logout handler
├── index.php                # Landing page
└── README.md                # Dokumentasi ini