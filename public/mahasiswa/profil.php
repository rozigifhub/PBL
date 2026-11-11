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

        <!-- ===== BARIS 2: Alamat Pengiriman (lebar penuh) ===== -->
        <section class="pf-card pf-card--alamat" aria-labelledby="pf-h-alamat">
            <div class="pf-card__head">
                <img class="pf-card__icon" src="/assets/img/icon-profil-home.png" alt="" width="115" height="130">
                <h2 id="pf-h-alamat">Alamat Pengiriman</h2>
            </div>
            <p class="pf-sr">Alamat default otomatis terisi saat checkout (tetap dapat diubah per pesanan).</p>

            <!-- PHP (asli dari backend): pesan error alamat dari $errorAlamat -->
            <?php if ($errorAlamat !== ""): ?>
                <p class="pf-alert pf-alert--err" role="alert"><?= e($errorAlamat) ?></p>
            <?php endif; ?>

            <!-- PHP (asli dari backend): form tambah alamat hanya tampil selama jumlah alamat < MAKS_ALAMAT.
                 Form di atas daftar (sesuai desain). name="label" & name="alamat", hidden action="tambah" TIDAK diubah.
                 value/isi ulang dari $labelInput dan $alamatInput saat validasi gagal. -->
            <?php if (count($daftarAlamat) < MAKS_ALAMAT): ?>
                <form class="pf-addform" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="tambah">

                    <div>
                        <div class="pf-field">
                            <label class="pf-label" for="pf-label">Label</label>
                            <input class="pf-input" type="text" id="pf-label" name="label" maxlength="50" required
                                   placeholder="Contoh: Rumah, Kos, Kampus"
                                   value="<?= e($labelInput) ?>">
                        </div>
                        <div class="pf-field">
                            <label class="pf-label" for="pf-alamat">Alamat lengkap</label>
                            <textarea class="pf-input" id="pf-alamat" name="alamat" rows="4" cols="50" maxlength="500" required
                                      placeholder="Contoh: Jl. Soekarno Hatta"><?= e($alamatInput) ?></textarea>
                        </div>
                    </div>

                    <button class="pf-btn" type="submit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.6" stroke-linecap="round" aria-hidden="true"><path d="M12 3.5v17M3.5 12h17"/></svg>
                        Tambah Alamat
                    </button>
                </form>
            <?php else: ?>
                <p class="pf-full">Batas <?= MAKS_ALAMAT ?> alamat tercapai. Hapus salah satu untuk menambah yang baru.</p>
            <?php endif; ?>

            <!-- PHP (asli dari backend): bila $daftarAlamat kosong tampilkan pesan, bila ada tampilkan daftar -->
            <?php if (!$daftarAlamat): ?>
                <p class="pf-empty">Belum ada alamat tersimpan.</p>
            <?php else: ?>
                <div class="pf-list" role="table" aria-label="Daftar alamat pengiriman">
                    <div class="pf-list__head" role="row">
                        <span class="pf-cell" role="columnheader">Label</span>
                        <span class="pf-cell" role="columnheader">Alamat</span>
                        <span class="pf-cell" role="columnheader">Aksi</span>
                    </div>

                    <!-- ============ MULAI LOOPING PHP ============
                         Ini looping asli dari backend: foreach ($daftarAlamat as $a).
                         Satu iterasi = satu <div class="pf-row"> (default sudah berada paling atas karena ORDER BY is_default DESC).
                         Kalau looping-mu diganti (while / nama variabel lain), ganti baris foreach dan endforeach saja;
                         isi di bawahnya memakai $a["..."] yang sama seperti kode lama (id_alamat, label, alamat, is_default). -->
                    <?php foreach ($daftarAlamat as $a): ?>
                        <div class="pf-row" role="row">

                            <!-- Label: $a["label"], plus "(Default)" bila $a["is_default"] -->
                            <div class="pf-cell" role="cell" data-label="Label">
                                <?= e($a["label"]) ?>
                                <?php if ($a["is_default"]): ?><span class="pf-default">(Default)</span><?php endif; ?>
                            </div>

                            <!-- Alamat: $a["alamat"] (nl2br agar baris baru tetap tampil) -->
                            <div class="pf-cell" role="cell" data-label="Alamat"><?= nl2br(e($a["alamat"])) ?></div>

                            <!-- Aksi: SET DEFAULT hanya untuk alamat yang belum default; HAPUS selalu ada.
                                 Dua form ini sama seperti kode lama (hidden csrf_token, action, id_alamat). -->
                            <div class="pf-cell pf-cell--aksi" role="cell">
                                <?php if (!$a["is_default"]): ?>
                                    <form class="pf-actform" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="default">
                                        <input type="hidden" name="id_alamat" value="<?= (int)$a["id_alamat"] ?>">
                                        <button class="pf-act pf-act--set" type="submit">
                                            <img src="/assets/img/icon-profil-edit.png" alt="" width="27" height="27">
                                            Set Default
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form class="pf-actform" method="POST" onsubmit="return confirm('Hapus alamat ini?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="hapus">
                                    <input type="hidden" name="id_alamat" value="<?= (int)$a["id_alamat"] ?>">
                                    <button class="pf-act pf-act--del" type="submit">
                                        <svg class="pf-trash" viewBox="0 0 41 41" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" aria-hidden="true">
                                            <path d="M17.08 25.63V20.5M23.92 25.63V20.5"/>
                                            <path d="M10.25 15.96V30.17C10.25 32.05 10.25 32.99 10.84 33.58 11.42 34.17 12.36 34.17 14.25 34.17H26.75C28.64 34.17 29.58 34.17 30.16 33.58 30.75 32.99 30.75 32.05 30.75 30.17V15.96"/>
                                            <g class="pf-trash__lid">
                                                <path d="M5.13 11.96H35.88"/>
                                                <path d="M17.2 5.76C17.39 5.58 17.82 5.42 18.42 5.3 19.02 5.19 19.75 5.13 20.5 5.13 21.25 5.13 21.98 5.19 22.58 5.3 23.18 5.42 23.61 5.58 23.8 5.76"/>
                                            </g>
                                        </svg>
                                        Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <!-- ============ SELESAI LOOPING PHP ============ -->
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

</body>
</html>
