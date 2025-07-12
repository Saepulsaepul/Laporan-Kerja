<?php
require_once 'includes/functions.php';

session_start();

// Cek tipe user yang logout
if (isset($_SESSION['admin_id'])) {
    logout('admin');
} else {
    logout('user');
}

redirect('login.php');
?>

