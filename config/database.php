<?php
// ===============================
// KONFIGURASI DATABASE (DOCKER)
// ===============================

define('DB_HOST', 'db');               // Nama service mysql di docker-compose
define('DB_PORT', '3306');             // Port internal antar container
define('DB_USER', 'root');             // User sesuai docker-compose
define('DB_PASS', 'root');             // Password sesuai docker-compose
define('DB_NAME', 'website_pelaporan'); // Nama database

// ===============================
// MEMBUAT KONEKSI PDO
// ===============================
function getConnection() {
    try {
        // DSN koneksi ke MySQL
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8";

        // Buat PDO
        $pdo = new PDO($dsn, DB_USER, DB_PASS);

        // Mode error
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mode fetch: associative array
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;

    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// ===============================
// TEST KONEKSI
// ===============================
function testConnection() {
    try {
        $pdo = getConnection();
        return true;
    } catch(Exception $e) {
        return false;
    }
}
