<?php

// Dashboard mahasiswa: data diri + navigasi.

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

// Profil mahasiswa (join akun_login untuk email)
$stmt = $pdo->prepare("
    SELECT m.nim, m.nama, m.no_hp, m.alamat, a.email
    FROM mahasiswa m
    JOIN akun_login a ON a.id_akun = m.id_akun
    WHERE m.id_akun = :id_akun
");
$stmt->execute([":id_akun" => $_SESSION["id_akun"]]);
$profil = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Mahasiswa - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<main>
    <h1>Dashboard Mahasiswa</h1>

    <p>
        Selamat datang,
        <b><?= e($_SESSION["username"]) ?></b>
    </p>

    <h2>Data Diri</h2>

    <table cellpadding="6">
        <tr>
            <td><b>NIM</b></td>
            <td><?= e($profil["nim"] ?? "-") ?></td>
        </tr>
        <tr>
            <td><b>Nama</b></td>
            <td><?= e($profil["nama"] ?? "-") ?></td>
        </tr>
        <tr>
            <td><b>Email</b></td>
            <td><?= e($profil["email"] ?? "-") ?></td>
        </tr>
        <tr>
            <td><b>No. HP</b></td>
            <td><?= e($profil["no_hp"] ?? "-") ?></td>
        </tr>
        <tr>
            <td><b>Alamat</b></td>
            <td>
                <?= $profil["alamat"] ? nl2br(e($profil["alamat"])) : "<i>Belum ada — isi di halaman profil</i>" ?>
            </td>
        </tr>
    </table>

    <p>
        <a href="katalog.php"><b>Lihat Katalog</b></a> |
        <a href="profil.php">Kelola Profil</a> |
        <a href="/logout.php">Logout</a>
    </p>

</main>

</body>
</html>
