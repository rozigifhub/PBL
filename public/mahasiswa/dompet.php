<?php

/**
 * Dompet mahasiswa: saldo, top up via Midtrans, riwayat mutasi.
 *
 * Saldo dipakai untuk membayar pesanan. Penarikan (withdraw) diproses manual
 * oleh admin: pengajuan withdraw tercatat di withdraw_dompet (saldo ditahan), lalu WhatsApp admin terbuka dengan pesan terisi
 * (nomor WA_ADMIN di config/dompet.php). Refund pesanan masuk ke sini.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_once __DIR__ . "/../../config/dompet.php";
require_mahasiswa();

$idAkun = (int)$_SESSION["id_akun"];
$error  = "";
$flash  = take_flash();

/** Tautan WhatsApp admin dengan pesan withdraw terisi (nomor admin: WA_ADMIN di config/dompet.php). */
function link_withdraw(PDO $pdo, int $idAkun, int $idWithdraw, int $jumlah, string $tujuan): string
{
    $stmt = $pdo->prepare("SELECT nim, nama FROM mahasiswa WHERE id_akun = :id");
    $stmt->execute([":id" => $idAkun]);
    $mhs = $stmt->fetch() ?: ["nim" => "-", "nama" => (string)($_SESSION["username"] ?? "")];

    $pesan = "Halo admin, saya mengajukan withdraw dompet.\n"
           . "ID permintaan: #" . $idWithdraw . "\n"
           . "Nama: " . $mhs["nama"] . "\n"
           . "NIM: " . $mhs["nim"] . "\n"
           . "Jumlah: Rp " . number_format($jumlah, 0, ",", ".") . "\n"
           . "Tujuan: " . $tujuan . "\n"
           . "Mohon diproses, terima kasih.";

    return "https://wa.me/" . preg_replace("/\D/", "", WA_ADMIN) . "?text=" . rawurlencode($pesan);
}

/**
 * HELPER TAMPILAN (bukan logika backend): ubah "2026-11-10 22:23:41" menjadi "10 Nov 2026, 22:23".
 * Memotong string apa adanya (tanpa konversi zona waktu), jadi jam yang tampil sama persis dengan yang disimpan database.
 */
function tampil_tanggal_mutasi(string $waktu): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $waktu, $p)) {
        return substr($waktu, 0, 16);
    }
    $bulan = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
    return $p[3] . " " . ($bulan[(int)$p[2] - 1] ?? $p[2]) . " " . $p[1] . ", " . $p[4] . ":" . $p[5];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } elseif (($_POST["action"] ?? "") === "withdraw") {
        $jumlah    = (int)preg_replace("/\D/", "", (string)($_POST["jumlah"] ?? ""));
        $tujuan    = trim((string)($_POST["tujuan"] ?? ""));
        $saldoKini = dompet_saldo($pdo, $idAkun);

        if ($jumlah < WITHDRAW_MINIMAL) {
            $error = "Jumlah withdraw minimal Rp " . number_format(WITHDRAW_MINIMAL, 0, ",", ".") . ".";
        } elseif ($jumlah > $saldoKini) {
            $error = "Jumlah withdraw melebihi saldo (Rp " . number_format($saldoKini, 0, ",", ".") . ").";
        } elseif ($tujuan === "" || mb_strlen($tujuan) > 200) {
            $error = "Tujuan transfer wajib diisi (maks 200 karakter), mis. BCA 1234567890 a.n. Nama.";
        } else {
            try {
                // Permintaan dibuat + saldo ditahan dalam satu function database
                $stmt = $pdo->prepare("SELECT fn_withdraw_ajukan(:akun, :jumlah, :tujuan)");
                $stmt->execute([":akun" => $idAkun, ":jumlah" => $jumlah, ":tujuan" => $tujuan]);
                $idWd = (int)$stmt->fetchColumn();

                set_flash("sukses", "Permintaan withdraw #{$idWd} dibuat. Saldo ditahan sampai admin memproses.");
                redirect(link_withdraw($pdo, $idAkun, $idWd, $jumlah, $tujuan)); // buka WhatsApp admin
            } catch (PDOException $e) {
                error_log("Withdraw gagal: " . $e->getMessage());
                $error = str_contains($e->getMessage(), "Saldo dompet tidak cukup")
                    ? "Saldo dompet tidak cukup."
                    : "Terjadi kesalahan database. Coba lagi.";
            }
        }
    } else {
        $jumlah = (int)preg_replace("/\D/", "", (string)($_POST["jumlah"] ?? ""));

        if ($jumlah < TOPUP_MINIMAL || $jumlah > TOPUP_MAKSIMAL) {
            $error = "Jumlah top up harus antara Rp " . number_format(TOPUP_MINIMAL, 0, ",", ".")
                   . " dan Rp " . number_format(TOPUP_MAKSIMAL, 0, ",", ".") . ".";
        } else {
            try {
                [$ok, $hasil] = midtrans_buat_topup($pdo, $idAkun, $jumlah);

                if ($ok) {
                    redirect($hasil); // keluar ke halaman pembayaran Midtrans
                }
                $error = $hasil;
            } catch (PDOException $e) {
                error_log("Top up gagal: " . $e->getMessage());
                $error = "Terjadi kesalahan database. Coba lagi.";
            }
        }
    }
}

$saldo = dompet_saldo($pdo, $idAkun);

// Permintaan withdraw milik user ini (status: Diproses / Selesai / Ditolak)
$daftarWd = [];
try {
    $stmt = $pdo->prepare("
        SELECT id_withdraw, jumlah, tujuan, status, catatan_admin, created_at, diproses_pada
        FROM withdraw_dompet
        WHERE id_akun = :id
        ORDER BY id_withdraw DESC
        LIMIT 10
    ");
    $stmt->execute([":id" => $idAkun]);
    $daftarWd = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Daftar withdraw gagal dibaca: " . $e->getMessage());
}

$stmt = $pdo->prepare("
    SELECT created_at, jenis, jumlah, saldo_sesudah, id_pesanan, keterangan
    FROM mutasi_dompet
    WHERE id_akun = :id
    ORDER BY id_mutasi DESC
    LIMIT 30
");
$stmt->execute([":id" => $idAkun]);
$mutasi = $stmt->fetchAll();

// Top up yang belum dibayar (tautan masih bisa dilanjutkan)
$stmt = $pdo->prepare("
    SELECT order_id, jumlah, payment_url, created_at
    FROM topup_dompet
    WHERE id_akun = :id AND status = 'Menunggu'
      AND created_at > now() - INTERVAL '60 minutes'
    ORDER BY id_topup DESC
");
$stmt->execute([":id" => $idAkun]);
$menunggu = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dompet Saya - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/dompet.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dompet.css") ?>">
    <script src="/assets/js/dompet.js?v=<?= @filemtime(__DIR__ . "/../assets/js/dompet.js") ?>" defer></script>
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar.php";
?>

<main class="dp">
<div class="dp__inner">

    <!-- ===== BREADCRUMB: ikon rumah + "DASHBOARD / DOMPET SAYA" ===== -->
    <nav class="dp-crumb" aria-label="Navigasi halaman">
        <a class="dp-crumb__home" href="/mahasiswa/dashboard.php" aria-label="Dashboard"><img src="/assets/img/dompet-ic-home.png" alt=""></a>
        <p class="dp-crumb__trail"><a href="/mahasiswa/dashboard.php">Dashboard</a> / <a href="/mahasiswa/dompet.php" aria-current="page">Dompet Saya</a></p>
    </nav>

    <!-- ===== HEADER: ikon dompet oranye + judul + sub-judul ===== -->
    <header class="dp-head">
        <span class="dp-head__icon"><img src="/assets/img/dompet-ic-wallet-white.png" alt=""></span>
        <div>
            <h1 class="dp-head__title">Dompet Saya</h1>
            <p class="dp-head__sub">Detail riwayat mutasi dan top up</p>
        </div>
    </header>

    <!-- PHP: PESAN FLASH DARI BACKEND ($flash) — kondisi if asli dipertahankan -->
    <?php if ($flash): ?>
        <p class="alert <?= $flash["type"] === "sukses" ? "alert--success" : "alert--error" ?>" role="status"><b><?= e($flash["msg"]) ?></b></p>
    <?php endif; ?>

    <!-- PHP: PESAN ERROR VALIDASI / MIDTRANS ($error) — kondisi if asli dipertahankan -->
    <?php if ($error !== ""): ?>
        <p class="alert alert--error" role="alert"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <!-- ===== LAYOUT DUA KOLOM: kiri = Saldo Anda + Metode Pembayaran, kanan = Riwayat Mutasi ===== -->
    <div class="dp-grid">

        <div class="dp-col dp-col--kiri">

            <!-- ===== CARD 1: SALDO ANDA (nominal saldo + form Withdraw) ===== -->
            <section class="dp-card dp-card--saldo" aria-labelledby="dp-t-saldo">
                <div class="dp-card__head">
                    <img src="/assets/img/dompet-ic-wallet-blue.png" alt="">
                    <h2 class="dp-card__title" id="dp-t-saldo">Saldo Anda</h2>
                    <p class="dp-card__note">Saldo dipakai untuk bayar pesanan.Refund masuk ke sini.</p>
                </div>

                <!-- PHP: NOMINAL SALDO ($saldo dari dompet_saldo()). Tampil "Rp. 50.000,00"; kelas --sm dipasang bila angkanya sangat panjang -->
                <?php $saldoTeks = "Rp. " . number_format($saldo, 2, ",", "."); ?>
                <div class="dp-saldo">
                    <p class="dp-saldo__val<?= strlen($saldoTeks) > 14 ? " dp-saldo__val--sm" : "" ?>"><?= $saldoTeks ?></p>
                </div>

                <!-- PHP: FORM WITHDRAW — hanya tampil jika $saldo >= WITHDRAW_MINIMAL (if/else asli dipertahankan).
                     method POST, csrf_token, action=withdraw, name="jumlah" dan name="tujuan" TIDAK diubah. -->
                <?php if ($saldo >= WITHDRAW_MINIMAL): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="withdraw">

                        <div class="dp-wd">
                            <div class="dp-field dp-field--all">
                                <label class="dp-label" for="wd-jumlah">Jumlah yang ingin ditarik</label>
                                <input class="dp-input" type="number" id="wd-jumlah" name="jumlah" min="<?= WITHDRAW_MINIMAL ?>" max="<?= (int)floor($saldo) ?>"
                                       step="1" placeholder="Minimal <?= number_format(WITHDRAW_MINIMAL, 0, ",", ".") ?>, Maksimal sebesar saldo" required>
                                <!-- Tombol "Semua": mengisi kolom jumlah dengan seluruh saldo (onclick asli dipertahankan) -->
                                <button type="button" class="dp-field__all" onclick="this.form.jumlah.value=<?= (int)floor($saldo) ?>">Semua</button>
                            </div>
                            <div class="dp-field">
                                <label class="dp-label" for="wd-tujuan">Tujuan transfer</label>
                                <input class="dp-input" type="text" id="wd-tujuan" name="tujuan" maxlength="200"
                                       placeholder="Mis. BCA 123456789 a.n Nama" required>
                            </div>
                        </div>

                        <div class="dp-actions">
                            <p class="dp-hint">Saldo langsung ditahan, lalu Anda diarahkan ke WhatsApp admin dengan pesan yang sudah terisi. Jika ditolak, saldo kembali otomatis.</p>
                            <!-- Tombol kirim asli (type="submit") — dulu "Ajukan Withdraw" -->
                            <button type="submit" class="dp-btn dp-btn--wd">
                                <img src="/assets/img/dompet-ic-wallet-white.png" alt="">
                                <span>Withdraw</span>
                                <span class="dp-btn__arrow" aria-hidden="true"><svg viewBox="0 0 26 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 8h22.5M17 1.8 24 8l-7 6.2"/></svg></span>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="dp-empty">Saldo belum cukup untuk withdraw (minimal Rp <?= number_format(WITHDRAW_MINIMAL, 0, ",", ".") ?>).</p>
                <?php endif; ?>
            </section>

            <!-- ===== CARD 2: METODE PEMBAYARAN (form Top Up via Midtrans) ===== -->
            <section class="dp-card dp-card--topup" aria-labelledby="dp-t-topup">
                <div class="dp-card__head">
                    <img src="/assets/img/dompet-ic-card-blue.png" alt="">
                    <h2 class="dp-card__title" id="dp-t-topup">Metode Pembayaran</h2>
                </div>

                <!-- FORM TOP UP ASLI: method POST, csrf_token, name="jumlah" TIDAK diubah (backend: midtrans_buat_topup()).
                     data-dp-topup dipakai assets/js/dompet.js untuk efek pill aktif. -->
                <form method="POST" class="dp-tu" data-dp-topup>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <!-- PHP: TOMBOL NOMINAL CEPAT (foreach asli). Pill 50.000 aktif bawaan; klik = isi kolom "Nominal Lainnya" (lihat dompet.js) -->
                    <div class="dp-pills" role="group" aria-label="Pilih nominal top up">
                        <?php foreach ([20000, 50000, 100000, 200000] as $n): ?>
                            <button type="button" class="dp-pill<?= $n === 50000 ? " is-active" : "" ?>" data-dp-nominal="<?= $n ?>" aria-pressed="<?= $n === 50000 ? "true" : "false" ?>">Rp. <?= number_format($n, 0, ",", ".") ?></button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Kolom input asli name="jumlah" (min, max, step, required dipertahankan) -->
                    <div class="dp-field dp-field--nominal">
                        <label class="dp-label" for="tu-jumlah">Nominal lainnya</label>
                        <input class="dp-input" type="number" id="tu-jumlah" name="jumlah" min="<?= TOPUP_MINIMAL ?>" max="<?= TOPUP_MAKSIMAL ?>"
                               step="1000" placeholder="Masukkan nominal" required>
                    </div>

                    <div class="dp-actions">
                        <p class="dp-hint">Top up Rp <?= number_format(TOPUP_MINIMAL, 0, ",", ".") ?> – Rp <?= number_format(TOPUP_MAKSIMAL, 0, ",", ".") ?>, dibayar lewat Midtrans.</p>
                        <!-- Tombol kirim asli (type="submit") — dulu "Top Up via Midtrans" -->
                        <button type="submit" class="dp-btn dp-btn--tu">
                            <img src="/assets/img/dompet-ic-card-white.png" alt="">
                            <span>Top up via Midtrans</span>
                            <span class="dp-btn__arrow" aria-hidden="true"><svg viewBox="0 0 26 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 8h22.5M17 1.8 24 8l-7 6.2"/></svg></span>
                        </button>
                    </div>
                </form>

                <!-- PHP: TOP UP MENUNGGU PEMBAYARAN ($menunggu) — hanya tampil bila ada; if & foreach asli, tautan payment_url Midtrans TIDAK diubah -->
                <?php if ($menunggu): ?>
                    <div class="dp-pending">
                        <h3 class="dp-pending__title">Top up menunggu pembayaran</h3>
                        <ul class="dp-pending__list">
                            <!-- PHP: LOOPING TOP UP MENUNGGU MULAI DARI SINI -->
                            <?php foreach ($menunggu as $t): ?>
                                <li class="dp-pending__item">
                                    <span>Rp <?= number_format((float)$t["jumlah"], 0, ",", ".") ?> (<?= e(substr($t["created_at"], 0, 16)) ?>)</span>
                                    <a class="dp-pending__link" href="<?= e($t["payment_url"]) ?>">Lanjutkan pembayaran</a>
                                </li>
                            <?php endforeach; ?>
                            <!-- PHP: LOOPING TOP UP MENUNGGU SELESAI DI SINI -->
                        </ul>
                    </div>
                <?php endif; ?>
            </section>

        </div>

        <div class="dp-col dp-col--kanan">

            <!-- ===== CARD 3: RIWAYAT MUTASI (tabel bawaan diganti daftar modern; header krem, tanpa garis vertikal) ===== -->
            <section class="dp-card dp-card--mutasi" aria-labelledby="dp-t-mutasi">
                <div class="dp-card__head">
                    <img src="/assets/img/dompet-ic-refresh.png" alt="">
                    <h2 class="dp-card__title" id="dp-t-mutasi">Riwayat Mutasi</h2>
                </div>

                <!-- PHP: JIKA BELUM ADA MUTASI ($mutasi kosong) — if/else asli dipertahankan -->
                <?php if (!$mutasi): ?>
                    <p class="dp-kosong">Belum ada mutasi.</p>
                <?php else: ?>
                    <div class="dp-table-wrap">
                        <table class="dp-table">
                            <thead>
                                <tr>
                                    <th class="dp-c-tgl" scope="col">Tanggal</th>
                                    <th class="dp-c-ket" scope="col">Keterangan</th>
                                    <th class="dp-c-nom" scope="col">Nominal</th>
                                    <th class="dp-c-sld" scope="col">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- PHP: LOOPING RIWAYAT MUTASI MULAI DARI SINI (foreach asli; variabel $m dan $masuk tidak diubah) -->
                                <?php foreach ($mutasi as $m): ?>
                                    <?php $masuk = (float)$m["jumlah"] > 0; ?>
                                    <tr>
                                        <td class="dp-c-tgl"><?= e(tampil_tanggal_mutasi((string)$m["created_at"])) ?></td>
                                        <td class="dp-c-ket">
                                            <span class="dp-sr"><?= e($m["jenis"]) ?>: </span><?= e($m["keterangan"] ?? "") ?>
                                            <?php if ($m["id_pesanan"]): ?>
                                                (<a class="dp-order" href="/mahasiswa/pesanan.php?id=<?= (int)$m["id_pesanan"] ?>">#<?= (int)$m["id_pesanan"] ?></a>)
                                            <?php endif; ?>
                                        </td>
                                        <!-- PHP: NOMINAL — hijau (+) bila $masuk, merah (-) bila keluar; kolom saldo ikut warna barisnya seperti desain -->
                                        <td class="dp-c-nom <?= $masuk ? "dp-plus" : "dp-minus" ?>"><?= $masuk ? "+" : "-" ?> Rp. <?= number_format(abs((float)$m["jumlah"]), 0, ",", ".") ?></td>
                                        <td class="dp-c-sld <?= $masuk ? "dp-plus" : "dp-minus" ?>">Rp. <?= number_format((float)$m["saldo_sesudah"], 0, ",", ".") ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- PHP: LOOPING RIWAYAT MUTASI SELESAI DI SINI -->
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    </div>

    <!-- PHP: PERMINTAAN WITHDRAW ($daftarWd) — hanya tampil bila ada; if & foreach asli dipertahankan.
         Bagian ini tidak ada di desain Figma, jadi dibuat sebagai kartu lebar penuh di bawah grid utama. -->
    <?php if ($daftarWd): ?>
        <section class="dp-card dp-card--wdlist" aria-labelledby="dp-t-wdlist">
            <div class="dp-card__head">
                <img src="/assets/img/dompet-ic-wallet-blue.png" alt="">
                <h2 class="dp-card__title" id="dp-t-wdlist">Permintaan Withdraw</h2>
            </div>
            <div class="dp-table-wrap dp-wdlist">
                <table class="dp-table">
                    <thead>
                        <tr>
                            <th class="dp-w-id" scope="col">ID</th>
                            <th class="dp-w-tgl" scope="col">Diajukan</th>
                            <th class="dp-w-jml" scope="col">Jumlah</th>
                            <th scope="col">Tujuan</th>
                            <th class="dp-w-sts" scope="col">Status</th>
                            <th scope="col">Catatan admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- PHP: LOOPING PERMINTAAN WITHDRAW MULAI DARI SINI (foreach asli; variabel $w tidak diubah) -->
                        <?php foreach ($daftarWd as $w): ?>
                            <?php $kelasStatus = ["Diproses" => "dp-badge--proses", "Selesai" => "dp-badge--selesai", "Ditolak" => "dp-badge--tolak"][$w["status"]] ?? ""; ?>
                            <tr>
                                <td>#<?= (int)$w["id_withdraw"] ?></td>
                                <td><?= e(tampil_tanggal_mutasi((string)$w["created_at"])) ?></td>
                                <td>Rp <?= number_format((float)$w["jumlah"], 0, ",", ".") ?></td>
                                <td><?= e($w["tujuan"]) ?></td>
                                <td>
                                    <span class="dp-badge <?= $kelasStatus ?>"><?= e($w["status"]) ?></span>
                                    <?php if ($w["diproses_pada"]): ?>
                                        <small class="dp-badge__time"><?= e(tampil_tanggal_mutasi((string)$w["diproses_pada"])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($w["catatan_admin"] ?? "") ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- PHP: LOOPING PERMINTAAN WITHDRAW SELESAI DI SINI -->
                    </tbody>
                </table>
            </div>
            <p class="dp-hint dp-wdnote">Diproses = menunggu admin. Selesai = sudah ditransfer. Ditolak = saldo dikembalikan ke dompet.</p>
        </section>
    <?php endif; ?>

</div>
</main>

</body>
</html>
