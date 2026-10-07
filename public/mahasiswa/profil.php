<?php

/**
 * Profil mahasiswa: data diri + alamat default pengiriman.
 *
 * Alamat yang disimpan di sini otomatis terisi pada form checkout
 * (tetap dapat diubah per pesanan).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

$error = "";
$flash = take_flash();

// Ambil data profil (join akun_login untuk email)
$stmt = $pdo->prepare("
    SELECT m.nim, m.nama, m.no_hp, m.alamat, a.email
    FROM mahasiswa m
    JOIN akun_login a ON a.id_akun = m.id_akun
    WHERE m.id_akun = :id_akun
");
$stmt->execute([":id_akun" => $_SESSION["id_akun"]]);
$profil = $stmt->fetch();

if ($profil === false) {
    redirect("/mahasiswa/dashboard.php");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $alamat = trim($_POST["alamat"] ?? "");

        if ($alamat === "") {
            $error = "Alamat wajib diisi.";
        } elseif (strlen($alamat) > 500) {
            $error = "Alamat maksimal 500 karakter.";
        } else {
            $stmt = $pdo->prepare("UPDATE mahasiswa SET alamat = :alamat WHERE id_akun = :id_akun");
            $stmt->execute([":alamat" => $alamat, ":id_akun" => $_SESSION["id_akun"]]);
            set_flash("sukses", "Alamat berhasil disimpan.");
            redirect("/mahasiswa/profil.php");
        }

        // Saat error, pertahankan input terakhir di form
        $profil["alamat"] = $_POST["alamat"] ?? $profil["alamat"];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil Saya - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<main>
    <h1>Profil Saya</h1>
    <p>
        <a href="/mahasiswa/dashboard.php">&larr; Dashboard</a> |
        <a href="/mahasiswa/katalog.php">Katalog</a>
    </p>

    <?php if ($flash): ?>
        <p style="color:<?= $flash["type"] === "sukses" ? "#0a7d32" : "#b00020" ?>;">
            <b><?= e($flash["msg"]) ?></b>
        </p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p style="color:#b00020;"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <h2>Data Diri</h2>

    <table cellpadding="6">
        <tr>
            <td><b>NIM</b></td>
            <td><?= e($profil["nim"]) ?></td>
        </tr>
        <tr>
            <td><b>Nama</b></td>
            <td><?= e($profil["nama"]) ?></td>
        </tr>
        <tr>
            <td><b>Email</b></td>
            <td><?= e($profil["email"]) ?></td>
        </tr>
        <tr>
            <td><b>No. HP</b></td>
            <td><?= e($profil["no_hp"]) ?></td>
        </tr>
    </table>

    <h2>Alamat Default Pengiriman</h2>
    <p><i>Alamat ini otomatis terisi saat checkout (tetap dapat diubah per pesanan).</i></p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <textarea name="alamat" rows="4" cols="50" maxlength="500"
                  placeholder="Contoh: Jl. Kenanga No. 10, RT 02/RW 03, Surabaya"
                  required><?= e($profil["alamat"] ?? "") ?></textarea>

        <p>
            <button type="submit"><b>Simpan Alamat</b></button>
        </p>
    </form>

</main>

</body>
</html>
