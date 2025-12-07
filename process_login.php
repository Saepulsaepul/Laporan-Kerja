<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST['form_type'])) {
    redirect("login.php");
    exit;
}

$username = sanitizeInput($_POST['username']);
$password = $_POST['password'];
$form_type = $_POST['form_type'];

if (empty($username) || empty($password)) {
    $_SESSION['login_error'] = "Username dan Password tidak boleh kosong!";
    $_SESSION['login_error_type'] = $form_type;
    redirect("login.php");
    exit;
}

try {
    $pdo = getConnection();

    if ($form_type === "admin") {

        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password'])) {
            $_SESSION['login_error'] = "Username atau Password salah!";
            $_SESSION['login_error_type'] = "admin";
            redirect("login.php");
            exit;
        }

        $_SESSION['admin_id']       = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_nama']     = $admin['nama'];
        $_SESSION['role']           = "admin";

        redirect("admin/dashboard.php");
        exit;
    }

    if ($form_type === "user") {

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $_SESSION['login_error'] = "Username atau Password salah!";
            $_SESSION['login_error_type'] = "user";
            redirect("login.php");
            exit;
        }

        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nama']     = $user['nama'];
        $_SESSION['role']     = "pekerja";

        redirect("user/dashboard.php"); // tanpa slash!
        exit;
    }

} catch (Exception $e) {
    $_SESSION['login_error'] = "Terjadi gangguan pada sistem.";
    $_SESSION['login_error_type'] = $form_type;
    redirect("login.php");
    exit;
}
