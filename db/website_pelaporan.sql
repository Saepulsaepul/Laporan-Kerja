-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 12 Jul 2025 pada 10.48
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `website_pelaporan`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama_lengkap` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password`, `nama_lengkap`, `email`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@example.com', '2025-07-07 13:50:18', '2025-07-07 13:50:18');

-- --------------------------------------------------------

--
-- Struktur dari tabel `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `categories`
--

INSERT INTO `categories` (`id`, `section_id`, `category_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 1, 'Rancangan Penilaian ANDALALIN', 'Melakukan rancangan dan menilai rekomendasi terkait analisis dampak lalu lintas setiap permohonan rekomendasi ANDALALIN', '2025-07-07 13:50:18', '2025-07-09 14:13:37'),
(2, 2, 'Promosi dan Kemitraan Keselamatan Lalu Lintas', 'Melakukan kegiatan promosi dan kemitraan keselamatan lalu lintas dalam bentuk kerjasama pelaksanaan tugas di bidang keselamtan.', '2025-07-07 13:50:18', '2025-07-09 14:13:37'),
(3, 1, 'Fasilitas Perlengkapan Jalan Sesuai Kebutuhan dan Standar', 'Melakukan pencatatan dan penyediaan fasilitas perlengkapan jalan sesuai kebutuhan pada setiap kegiatan lalu lintas darat', '2025-07-07 13:50:18', '2025-07-09 14:13:37'),
(4, 2, 'Rancangan Analisis Daerah Rawan Kecelakaan', 'Proses mengidentifikasi daerah rawan kecelakaan dan menganalisis penyebabnya untuk mencari solusi pencegahan.', '2025-07-07 13:50:18', '2025-07-09 14:13:37'),
(5, 1, 'Pengendalian dan Pengawasan Tertib Lalu Lintas', 'Melakukan pengendalian dan pengawasan tertib lalu lintas serta melakukan pencatatan setiap kegiatan operasional di lapangan', '2025-07-08 08:30:39', '2025-07-09 14:13:37'),
(6, 2, 'Pengendalian dan Pengawasan Keselamatan Lalu Lintas', 'Melakukan tugas dalam hal mengendalikan dan mengawasi penjagaan setiap kegiatan di bidang lalu lintas', '2025-07-08 08:31:28', '2025-07-09 14:13:37'),
(7, 1, 'Rancangan Kebijakan Manajemen dan Rekayasa Lalu Lintas (MRLL)', 'Menyusun rancangan dan kebijakan terkait rekayasa lalu lintas di setiap kegiatan bidang lalu lintas darat', '2025-07-08 08:31:57', '2025-07-09 14:13:37');

-- --------------------------------------------------------

--
-- Struktur dari tabel `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `keterangan` text NOT NULL,
  `foto_bukti` varchar(255) DEFAULT NULL,
  `tanggal_pelaporan` date NOT NULL,
  `jam_pelaporan` time NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `reports`
--

INSERT INTO `reports` (`id`, `user_id`, `category_id`, `keterangan`, `foto_bukti`, `tanggal_pelaporan`, `jam_pelaporan`, `created_at`, `updated_at`) VALUES
(2, 5, 1, 'Bagus sekali nak', '1751898486_Cuplikan layar 2025-05-29 104845.png', '2025-07-07', '16:28:06', '2025-07-07 21:28:06', '2025-07-07 21:28:06'),
(4, 10, 5, 'Saya mohon maaf, sepertinya ada kesalahpahaman atau masalah teknis dalam interaksi kita. Saya adalah model AI dan tidak memiliki antarmuka pengguna grafis atau tombol &quot;edit&quot; yang bisa saya gunakan atau kendalikan. Ketika Anda mengatakan &quot;tombol edit tidak bisa digunakan dan hanya refresh halaman&quot;, ini mengindikasikan bahwa Anda sedang mengalami masalah pada aplikasi atau platform tempat Anda berinteraksi dengan saya (misalnya, di browser web, aplikasi seluler, atau antarmuka lain).', '1751940687_Gambar WhatsApp 2025-05-21 pukul 20.15.17_d0c83d3a.jpg', '2025-07-08', '04:11:27', '2025-07-08 09:11:27', '2025-07-08 09:11:27'),
(5, 2, 3, 'Melakukan kegiatan pembuatan sistem pelaporan yang di usulkan Pak Tedy dalam bahasa PHP', '1751942675_camera_photo.jpg', '2025-07-08', '09:44:35', '2025-07-08 09:44:35', '2025-07-08 09:44:35'),
(6, 2, 4, 'hi', '1751944117_camera_photo.jpg', '2025-07-08', '10:08:37', '2025-07-08 10:08:37', '2025-07-08 10:08:37'),
(7, 2, 1, 'menghadiri rapat', '1751944527_camera_photo.jpg', '2025-07-08', '10:15:27', '2025-07-08 10:15:27', '2025-07-08 10:15:27'),
(8, 2, 7, 'Perencaaan berjalan baik di jalan', '1751980686_camera-capture.jpg', '2025-07-08', '20:18:06', '2025-07-08 20:18:06', '2025-07-08 20:18:06'),
(9, 2, 7, 'Disini terpantau baik dan benar', '1752045397_camera-capture.jpg', '2025-07-09', '14:16:37', '2025-07-09 14:16:37', '2025-07-09 14:16:37');

-- --------------------------------------------------------

--
-- Struktur dari tabel `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `section_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `sections`
--

INSERT INTO `sections` (`id`, `section_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Manajemen dan Rekayasa Lalu Lintas', 'Seksi yang menangani manajemen dan rekayasa lalu lintas', '2025-07-09 14:13:37', '2025-07-09 14:13:37'),
(2, 'Keselamatan Lalu Lintas', 'Seksi yang menangani keselamatan lalu lintas', '2025-07-09 14:13:37', '2025-07-09 14:13:37');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama_lengkap` varchar(100) NOT NULL,
  `jabatan` varchar(100) DEFAULT 'Staff',
  `email` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `nama_lengkap`, `jabatan`, `email`, `created_at`, `updated_at`) VALUES
(2, 'amin', '$2y$10$zQ3YlOI/n4nCI3GMp3itQenLsTJrQPU8lwdHzNjj4e7xZI4N/se8K', 'Muhamad Nur Amin', 'Magang', 'amin@gmail.com', '2025-07-07 13:50:18', '2025-07-07 21:05:18'),
(5, 'bima', '$2y$10$J4VdYMTg2r7O47kcmv37jurvaGHkI0ce5S7DM3gD3e1x.hOuwlglK', 'Rifana Bima Pradifa', 'Magang', 'bima@gmail.com', '2025-07-07 21:10:44', '2025-07-07 21:10:44'),
(7, 'zulkifli', '$2y$10$QSUW8iIFZ.C/oKmZQeLhu.AHpLx1OYVBDKfDa8fMLACLn6kItlkme', 'Zulkifli, S.IP', 'Analisis Lalu Lintas', 'zulkifli@gmail.com', '2025-07-08 08:42:09', '2025-07-08 08:42:09'),
(8, 'firdis', '$2y$10$GpsY99q5W5mNV4zOVoseJONVyAU8o7vls7SQsPifekJ4oTQQvwquG', 'Firdis', 'pengadministrasi Kecelakaan LLAJ', 'firdis@gmail.com', '2025-07-08 09:07:53', '2025-07-08 09:07:53'),
(9, 'riska', '$2y$10$nsVVf5rAGsbPx8uzAnVj7ekacvlaCenQwqDHc8Evmu6A5CctIIcIK', 'Riska Utama, S.E', 'Penata Layanan Operasional', 'riska@gmail.com', '2025-07-08 09:08:58', '2025-07-08 09:08:58'),
(10, 'sugeng', '$2y$10$8pjORnvl7ZSWBUcbvhTnFedPjRtophS7uTU0HVYaeDsPIpld2X/fa', 'Sugeng Riyatmoko', 'Operator Layanan Operasional', 'sugeng@gmail.com', '2025-07-08 09:09:53', '2025-07-08 09:09:53'),
(11, 'febry', '$2y$10$VwJxYK0MWMBJLx0WpJH4ieAdWUKFrkMNczxPDCKZnnqKRl0zmxd0e', 'Febryanto Parulian Sinaga, S.AP', 'Penata Layanan Operasional', 'febry@gmail.com', '2025-07-08 10:17:47', '2025-07-08 10:17:47');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indeks untuk tabel `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`),
  ADD KEY `categories_ibfk_3` (`section_id`);

--
-- Indeks untuk tabel `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indeks untuk tabel `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `section_name` (`section_name`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
