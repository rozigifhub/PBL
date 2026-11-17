<?php

require_once __DIR__ . "/../config/functions.php";
app_session_start();

// Simpan role sebelum session dihapus, untuk menentukan halaman tujuan setelah logout
$role = $_SESSION["role"] ?? "";

$_SESSION = [];

// Hapus juga cookie session di browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Mahasiswa -> langsung ke halaman produk (katalog); Admin/lainnya -> halaman login
if ($role === "Mahasiswa") {
    header("Location: /mahasiswa/katalog.php");
} else {
    header("Location: /login.php");
}
exit;
