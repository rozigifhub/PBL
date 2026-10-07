<?php

// Beranda publik (landing page).

require_once __DIR__ . "/../config/functions.php";
app_session_start();
require_once __DIR__ . "/../config/database.php";

$jumlahProduk   = (int)$pdo->query("SELECT COUNT(*) FROM merchandise")->fetchColumn();
$jumlahKategori = (int)$pdo->query("SELECT COUNT(*) FROM kategori_merchandise")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchandise Kampus - JTI</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/includes/navbar.php"; ?>

<main>
    <h1>Merchandise Kampus</h1>
    <p>Technology for a Brighter Future — merchandise resmi untuk mahasiswa.</p>

    <p>
        Tersedia <b><?= $jumlahProduk ?></b> produk
        dalam <b><?= $jumlahKategori ?></b> kategori.
    </p>

    <?php if (empty($_SESSION["id_akun"])): ?>

        <p>
            Login untuk melihat katalog dan berbelanja:
        </p>
        <p>
            <a href="/login.php"><b>Login</b></a> |
            <a href="/register.php">Register</a>
        </p>

    <?php elseif (($_SESSION["role"] ?? "") === "Mahasiswa"): ?>

        <p><a href="/mahasiswa/katalog.php"><b>Lihat Katalog &rarr;</b></a> | <a href="/logout.php">Logout</a></p>

    <?php else: ?>

        <p><a href="/admin/dashboard.php"><b>Dashboard Admin</b></a> | <a href="/logout.php">Logout</a></p>

    <?php endif; ?>

</main>

</body>
</html>
