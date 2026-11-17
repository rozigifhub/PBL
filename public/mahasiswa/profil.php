<?php

/**
 * Profil mahasiswa: data diri dan ubah password.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

$idAkun        = (int)$_SESSION["id_akun"];
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

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $errorPassword = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
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
                $errorPassword = "Terjadi kesalahan database. Coba lagi.";
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil Saya - Merchandise Kampus</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/profil.css?v=<?= @filemtime(__DIR__ . "/../assets/css/profil.css") ?>">
    <script src="/assets/js/profil.js?v=<?= @filemtime(__DIR__ . "/../assets/js/profil.js") ?>" defer></script>
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar.php";
?>

<!-- Ikon dipakai berulang lewat <use>: mata (tampil/sembunyi password) dan coretan mata -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="pf-ic-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/>
        <circle cx="12" cy="12" r="3"/>
    </symbol>
    <symbol id="pf-ic-slash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
        <path d="M4 4l16 16"/>
    </symbol>
</svg>

<main class="pf">
    <div class="pf__inner">

        <!-- Kepala halaman: breadcrumb + avatar oranye + judul -->
        <header class="pf-head">
            <nav class="pf-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
                <span><a href="/mahasiswa/dashboard.php">Dashboard</a> / <a href="/mahasiswa/profil.php" aria-current="page">Kelola Akun</a></span>
            </nav>
            <div class="pf-title">
                <span class="pf-title__icon" aria-hidden="true"><img src="/assets/img/icon-profil-user.png" alt="" width="114" height="130"></span>
                <div>
                    <h1>Kelola Akun</h1>
                    <p>Detail informasi akun dan dapat mengganti data yang diinginkan</p>
                </div>
            </div>
        </header>

        <!-- PHP (asli dari backend): pesan sukses / gagal setelah simpan. Tetap memakai $flash dari take_flash(). -->
        <?php if ($flash): ?>
            <p class="pf-alert pf-flash pf-alert--<?= $flash["type"] === "sukses" ? "ok" : "err" ?>" role="status"><?= e($flash["msg"]) ?></p>
        <?php endif; ?>

        <!-- ===== BARIS 1: Data Diri (kiri) + Ubah Password (kanan) ===== -->
        <div class="pf-grid">

            <!-- Card Data Diri -->
            <section class="pf-card pf-card--data" aria-labelledby="pf-h-data">
                <div class="pf-card__head">
                    <span class="pf-card__icon pf-card__icon--user" aria-hidden="true"></span>
                    <h2 id="pf-h-data">Data Diri</h2>
                </div>

                <!-- PHP (asli dari backend): nilai diambil dari array $profil (query mahasiswa JOIN akun_login).
                     Titik dua dibuat CSS (dt::after) sehingga otomatis sejajar; jangan ketik ":" manual di sini. -->
                <dl class="pf-data">
                    <div class="pf-data__row">
                        <dt>Nama Lengkap</dt>
                        <dd><?= e($profil["nama"]) ?></dd>
                    </div>
                    <div class="pf-data__row">
                        <dt>NIM</dt>
                        <dd><?= e($profil["nim"]) ?></dd>
                    </div>
                    <div class="pf-data__row">
                        <dt>Email</dt>
                        <dd><?= e($profil["email"]) ?></dd>
                    </div>
                    <div class="pf-data__row">
                        <dt>No. HP</dt>
                        <dd><?= e($profil["no_hp"]) ?></dd>
                    </div>
                </dl>
            </section>

            <!-- Card Ubah Password -->
            <section class="pf-card pf-card--pass" aria-labelledby="pf-h-pass">
                <div class="pf-card__head">
                    <img class="pf-card__icon" src="/assets/img/icon-profil-lock.png" alt="" width="116" height="130">
                    <h2 id="pf-h-pass">Ubah Password</h2>
                </div>

                <!-- Form asli: method POST, hidden csrf_token + action="password", name input TIDAK diubah -->
                <form class="pf-passform" method="POST" autocomplete="off">
                    <!-- PHP (asli dari backend): pesan error password dari $errorPassword -->
                    <?php if ($errorPassword !== ""): ?>
                        <p class="pf-alert pf-alert--err" role="alert"><?= e($errorPassword) ?></p>
                    <?php endif; ?>

                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="password">

                    <div class="pf-field">
                        <label class="pf-label" for="pf-pw-lama">Password saat ini</label>
                        <div class="pf-pass">
                            <input class="pf-input" type="password" id="pf-pw-lama" name="password_lama" required autocomplete="current-password">
                            <button class="pf-eye" type="button" data-pf-eye aria-label="Tampilkan password" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><use href="#pf-ic-eye"/><use href="#pf-ic-slash" class="pf-eye__slash"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="pf-field">
                        <label class="pf-label" for="pf-pw-baru">Password baru</label>
                        <div class="pf-pass">
                            <input class="pf-input" type="password" id="pf-pw-baru" name="password_baru" minlength="8" required autocomplete="new-password" placeholder="Minimal 8 karakter">
                            <button class="pf-eye" type="button" data-pf-eye aria-label="Tampilkan password" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><use href="#pf-ic-eye"/><use href="#pf-ic-slash" class="pf-eye__slash"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="pf-field">
                        <label class="pf-label" for="pf-pw-konfirmasi">Konfirmasi password</label>
                        <div class="pf-pass">
                            <input class="pf-input" type="password" id="pf-pw-konfirmasi" name="password_konfirmasi" minlength="8" required autocomplete="new-password">
                            <button class="pf-eye" type="button" data-pf-eye aria-label="Tampilkan password" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><use href="#pf-ic-eye"/><use href="#pf-ic-slash" class="pf-eye__slash"/></svg>
                            </button>
                        </div>
                    </div>

                    <button class="pf-btn" type="submit">Ubah Password</button>
                </form>
            </section>
        </div>

    </div>
</main>

</body>
</html>
