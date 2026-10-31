<?php

/**
 * Profil mahasiswa: data diri, daftar alamat pengiriman (satu alamat default),
 * dan ubah password.
 *
 * Alamat default otomatis terisi pada form checkout (tetap dapat diubah per pesanan).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

const MAKS_ALAMAT = 10;

$idAkun        = (int)$_SESSION["id_akun"];
$errorAlamat   = "";
$errorPassword = "";
$flash         = take_flash();

// Ambil data profil (join akun_login untuk email)
$stmt = $pdo->prepare("
    SELECT m.nim, m.nama, m.no_hp, a.email
    FROM mahasiswa m
    JOIN akun_login a ON a.id_akun = m.id_akun
    WHERE m.id_akun = :id_akun
");
$stmt->execute([":id_akun" => $idAkun]);
$profil = $stmt->fetch();

if ($profil === false) {
    redirect("/mahasiswa/dashboard.php");
}

$labelInput  = "";
$alamatInput = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $errorAlamat = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            // ---- Tambah alamat ----
            if ($action === "tambah") {
                $labelInput  = trim($_POST["label"] ?? "");
                $alamatInput = trim($_POST["alamat"] ?? "");

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM alamat_mahasiswa WHERE id_akun = :id");
                $stmt->execute([":id" => $idAkun]);
                $jumlah = (int)$stmt->fetchColumn();

                if ($labelInput === "" || $alamatInput === "") {
                    $errorAlamat = "Label dan alamat wajib diisi.";
                } elseif (mb_strlen($labelInput) > 50) {
                    $errorAlamat = "Label maksimal 50 karakter.";
                } elseif (mb_strlen($alamatInput) > 500) {
                    $errorAlamat = "Alamat maksimal 500 karakter.";
                } elseif ($jumlah >= MAKS_ALAMAT) {
                    $errorAlamat = "Maksimal " . MAKS_ALAMAT . " alamat tersimpan.";
                } else {
                    // Alamat pertama otomatis jadi default
                    $stmt = $pdo->prepare("
                        INSERT INTO alamat_mahasiswa (id_akun, label, alamat, is_default)
                        VALUES (:id, :label, :alamat, :default)
                    ");
                    $stmt->bindValue(":id", $idAkun, PDO::PARAM_INT);
                    $stmt->bindValue(":label", $labelInput);
                    $stmt->bindValue(":alamat", $alamatInput);
                    $stmt->bindValue(":default", $jumlah === 0 ? "true" : "false"); // teks: aman di mode asli & emulasi
                    $stmt->execute();

                    set_flash("sukses", "Alamat berhasil ditambahkan.");
                    redirect("/mahasiswa/profil.php");
                }
            }

            // ---- Jadikan default ----
            if ($action === "default") {
                $idAlamat = (int)($_POST["id_alamat"] ?? 0);

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    SELECT 1 FROM alamat_mahasiswa
                    WHERE id_alamat = :id AND id_akun = :akun
                    FOR UPDATE
                ");
                $stmt->execute([":id" => $idAlamat, ":akun" => $idAkun]);

                if ($stmt->fetchColumn() === false) {
                    $pdo->rollBack();
                    $errorAlamat = "Alamat tidak ditemukan.";
                } else {
                    // Dua langkah: indeks unik parsial tidak mengizinkan dua default sekaligus
                    $stmt = $pdo->prepare("
                        UPDATE alamat_mahasiswa SET is_default = FALSE
                        WHERE id_akun = :akun AND is_default
                    ");
                    $stmt->execute([":akun" => $idAkun]);

                    $stmt = $pdo->prepare("
                        UPDATE alamat_mahasiswa SET is_default = TRUE
                        WHERE id_alamat = :id AND id_akun = :akun
                    ");
                    $stmt->execute([":id" => $idAlamat, ":akun" => $idAkun]);
                    $pdo->commit();

                    set_flash("sukses", "Alamat default diperbarui.");
                    redirect("/mahasiswa/profil.php");
                }
            }

            // ---- Hapus alamat ----
            if ($action === "hapus") {
                $idAlamat = (int)($_POST["id_alamat"] ?? 0);

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    DELETE FROM alamat_mahasiswa
                    WHERE id_alamat = :id AND id_akun = :akun
                    RETURNING is_default
                ");
                $stmt->execute([":id" => $idAlamat, ":akun" => $idAkun]);
                $hapus = $stmt->fetch();

                if ($hapus === false) {
                    $pdo->rollBack();
                    $errorAlamat = "Alamat tidak ditemukan.";
                } else {
                    // Yang dihapus adalah default → alamat tertua jadi default baru
                    if ($hapus["is_default"]) {
                        $stmt = $pdo->prepare("
                            UPDATE alamat_mahasiswa SET is_default = TRUE
                            WHERE id_alamat = (
                                SELECT id_alamat FROM alamat_mahasiswa
                                WHERE id_akun = :akun
                                ORDER BY id_alamat
                                LIMIT 1
                            )
                        ");
                        $stmt->execute([":akun" => $idAkun]);
                    }
                    $pdo->commit();

                    set_flash("sukses", "Alamat dihapus.");
                    redirect("/mahasiswa/profil.php");
                }
            }

            // ---- Ubah password ----
            if ($action === "password") {
                $lama     = $_POST["password_lama"] ?? "";
                $baru     = $_POST["password_baru"] ?? "";
                $konfirm  = $_POST["password_konfirmasi"] ?? "";

                $stmt = $pdo->prepare("SELECT password FROM akun_login WHERE id_akun = :id");
                $stmt->execute([":id" => $idAkun]);
                $hashLama = (string)$stmt->fetchColumn();

                if ($lama === "" || $baru === "" || $konfirm === "") {
                    $errorPassword = "Semua kolom password wajib diisi.";
                } elseif (!password_verify($lama, $hashLama)) {
                    $errorPassword = "Password saat ini salah.";
                } elseif (strlen($baru) < 8) {
                    $errorPassword = "Password baru minimal 8 karakter.";
                } elseif ($baru !== $konfirm) {
                    $errorPassword = "Konfirmasi password tidak sama.";
                } elseif (password_verify($baru, $hashLama)) {
                    $errorPassword = "Password baru harus berbeda dari password saat ini.";
                } else {
                    $stmt = $pdo->prepare("UPDATE akun_login SET password = :hash WHERE id_akun = :id");
                    $stmt->execute([":hash" => password_hash($baru, PASSWORD_DEFAULT), ":id" => $idAkun]);

                    session_regenerate_id(true);
                    set_flash("sukses", "Password berhasil diubah.");
                    redirect("/mahasiswa/profil.php");
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Profil mahasiswa gagal: " . $e->getMessage());
            if ($action === "password") {
                $errorPassword = "Terjadi kesalahan database. Coba lagi.";
            } else {
                $errorAlamat = "Terjadi kesalahan database. Coba lagi.";
            }
        }
    }
}

// Daftar alamat (default paling atas)
$stmt = $pdo->prepare("
    SELECT id_alamat, label, alamat, is_default
    FROM alamat_mahasiswa
    WHERE id_akun = :id
    ORDER BY is_default DESC, id_alamat
");
$stmt->execute([":id" => $idAkun]);
$daftarAlamat = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil Saya - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>

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

    <h2>Alamat Pengiriman</h2>
    <p><i>Alamat default otomatis terisi saat checkout (tetap dapat diubah per pesanan).</i></p>

    <?php if ($errorAlamat !== ""): ?>
        <p style="color:#b00020;"><b><?= e($errorAlamat) ?></b></p>
    <?php endif; ?>

    <?php if (!$daftarAlamat): ?>
        <p>Belum ada alamat tersimpan.</p>
    <?php else: ?>
        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Label</th>
                <th>Alamat</th>
                <th>Aksi</th>
            </tr>
            <?php foreach ($daftarAlamat as $a): ?>
                <tr>
                    <td>
                        <?= e($a["label"]) ?>
                        <?php if ($a["is_default"]): ?><br><b>(Default)</b><?php endif; ?>
                    </td>
                    <td><?= nl2br(e($a["alamat"])) ?></td>
                    <td>
                        <?php if (!$a["is_default"]): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="default">
                                <input type="hidden" name="id_alamat" value="<?= (int)$a["id_alamat"] ?>">
                                <button type="submit">Jadikan Default</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Hapus alamat ini?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="hapus">
                            <input type="hidden" name="id_alamat" value="<?= (int)$a["id_alamat"] ?>">
                            <button type="submit">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <?php if (count($daftarAlamat) < MAKS_ALAMAT): ?>
        <h3>Tambah Alamat</h3>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="tambah">

            <p>
                <label>Label (mis. Rumah, Kos, Kampus)</label><br>
                <input type="text" name="label" maxlength="50" required
                       value="<?= e($labelInput) ?>">
            </p>
            <p>
                <label>Alamat lengkap</label><br>
                <textarea name="alamat" rows="4" cols="50" maxlength="500"
                          placeholder="Contoh: Jl. Kenanga No. 10, RT 02/RW 03, Surabaya"
                          required><?= e($alamatInput) ?></textarea>
            </p>
            <p>
                <button type="submit"><b>Simpan Alamat</b></button>
            </p>
        </form>
    <?php else: ?>
        <p><i>Batas <?= MAKS_ALAMAT ?> alamat tercapai. Hapus salah satu untuk menambah yang baru.</i></p>
    <?php endif; ?>

    <h2>Ubah Password</h2>

    <?php if ($errorPassword !== ""): ?>
        <p style="color:#b00020;"><b><?= e($errorPassword) ?></b></p>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">

        <p>
            <label>Password saat ini</label><br>
            <input type="password" name="password_lama" required autocomplete="current-password">
        </p>
        <p>
            <label>Password baru (minimal 8 karakter)</label><br>
            <input type="password" name="password_baru" minlength="8" required autocomplete="new-password">
        </p>
        <p>
            <label>Konfirmasi password baru</label><br>
            <input type="password" name="password_konfirmasi" minlength="8" required autocomplete="new-password">
        </p>
        <p>
            <button type="submit"><b>Ubah Password</b></button>
        </p>
    </form>

</main>

</body>
</html>
