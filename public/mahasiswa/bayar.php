<?php

/**
 * Bayar pesanan: buat transaksi Snap sesuai kondisi pesanan.
 *
 *  - 'Menunggu Pembayaran' + persentase_dp > 0 → bayar DP (gross = persen × total)
 *  - 'Menunggu Pembayaran' + tanpa DP          → bayar penuh
 *  - 'DP Lunas' / 'Menunggu Pelunasan'         → bayar pelunasan (gross = sisa tagihan)
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_once __DIR__ . "/../../config/dompet.php";
require_mahasiswa();

$id = (int)($_GET["id"] ?? 0);

// Pesanan harus ada dan milik sendiri
$stmt = $pdo->prepare("
    SELECT id_pesanan, total_harga, status_pesanan, persentase_dp
    FROM pesanan
    WHERE id_pesanan = :id AND id_akun = :id_akun
");
$stmt->execute([":id" => $id, ":id_akun" => $_SESSION["id_akun"]]);
$pesanan = $stmt->fetch();

if ($pesanan === false) {
    redirect("/mahasiswa/riwayat.php");
}

$status   = $pesanan["status_pesanan"];
$total    = (float)$pesanan["total_harga"];
$persenDp = (int)$pesanan["persentase_dp"];

// Total yang sudah berhasil dibayar (attempt dengan status Berhasil)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(jumlah_bayar), 0)
    FROM pembayaran
    WHERE id_pesanan = :id AND status_bayar = 'Berhasil'
");
$stmt->execute([":id" => $id]);
$terbayar = (float)$stmt->fetchColumn();

if ($status === "Menunggu Pembayaran") {
    $jenis = $persenDp > 0 ? "DP" : "Full Payment";
    $gross = $persenDp > 0 ? $total * $persenDp / 100 : $total;
} elseif ($status === "DP Lunas" || $status === "Menunggu Pelunasan") {
    $jenis = "Pelunasan";
    $gross = $total - $terbayar;
} else {
    set_flash("gagal", "Pesanan ini tidak dapat dibayar (status: {$status}).");
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

if ($gross <= 0) {
    set_flash("gagal", "Tidak ada tagihan yang perlu dibayar.");
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

$saldo = dompet_saldo($pdo, (int)$_SESSION["id_akun"]);

// Ada saldo dan pilihan belum dibuat → tanya dulu (GET); tanpa saldo langsung ke Midtrans
if ($saldo > 0 && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $pakai = min($saldo, $gross);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bayar Pesanan #<?= $id ?> - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/bayar.css?v=<?= @filemtime(__DIR__ . "/../assets/css/bayar.css") ?>">
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar.php";

// TAMPILAN SAJA: teks judul menyesuaikan jenis tagihan ($jenis dari logika di atas, tidak diubah).
// Urutan: [teks breadcrumb terakhir, sub-judul, judul kartu]
$teks = [
    "Pelunasan"    => ["Pelunasan DP",  "Pelunasan DP dari Pesanan #",  "Metode Pelunasan"],
    "DP"           => ["Pembayaran DP", "Pembayaran DP dari Pesanan #", "Metode Pembayaran"],
    "Full Payment" => ["Pembayaran",    "Pembayaran penuh Pesanan #",   "Metode Pembayaran"],
][$jenis];
?>
<main class="by">
<div class="by__inner">

    <!-- ===== KEPALA HALAMAN: breadcrumb + ikon dompet oranye + judul + sub-judul ===== -->
    <header class="by-head">
        <nav class="by-crumb" aria-label="Breadcrumb">
            <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
            <span>
                <a href="/mahasiswa/dashboard.php">Dashboard</a> /
                <a href="/mahasiswa/riwayat.php">Riwayat Pesanan</a> /
                <!-- Pengganti tautan lama "← Kembali ke Pesanan" -->
                <a href="/mahasiswa/pesanan.php?id=<?= $id ?>">Detail Pesanan</a> /
                <span aria-current="page"><?= e($teks[0]) ?></span>
            </span>
        </nav>
        <div class="by-title">
            <span class="by-title__icon" aria-hidden="true"><img src="/assets/img/dompet-ic-wallet-white.png" alt=""></span>
            <div>
                <!-- PHP: ID PESANAN ($id) -->
                <h1>Pesanan #<?= $id ?></h1>
                <p><?= e($teks[1]) ?><?= $id ?></p>
            </div>
        </div>
    </header>

    <!-- ===== KARTU METODE PELUNASAN ===== -->
    <section class="by-card" aria-labelledby="by-t-metode">
        <h2 class="by-card__title" id="by-t-metode">
            <img src="/assets/img/dompet-ic-card-blue.png" alt="">
            <?= e($teks[2]) ?>
        </h2>

        <div class="by-body">
            <!-- PHP: NOMINAL TAGIHAN ($jenis & $gross dari logika di atas) -->
            <p class="by-tagihan"><span>Tagihan (<?= e($jenis) ?>): <b>Rp <?= number_format($gross, 0, ",", ".") ?></b></span></p>

            <!-- FORM ASLI: method POST, csrf_token, name "pakai_saldo" TIDAK diubah -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="by-opts">
                    <!-- PHP: PAKAI SALDO DOMPET (name="pakai_saldo" value="1", terpilih bawaan seperti checkbox lama) -->
                    <div class="by-opt">
                        <input class="by-opt__input" type="radio" id="bayar-saldo" name="pakai_saldo" value="1" checked>
                        <label class="by-opt__row" for="bayar-saldo">
                            <span class="by-opt__mark" aria-hidden="true"></span>
                            <span>
                                Gunakan saldo dompet (tersedia Rp <?= number_format($saldo, 0, ",", ".") ?>)
                                <small class="by-opt__note">
                                    <?php if ($saldo >= $gross): ?>
                                        Saldo cukup — tagihan lunas dari dompet tanpa pembayaran online.
                                    <?php else: ?>
                                        Saldo Rp <?= number_format($pakai, 0, ",", ".") ?> dipakai, sisa
                                        Rp <?= number_format($gross - $pakai, 0, ",", ".") ?> dibayar lewat Midtrans.
                                    <?php endif; ?>
                                </small>
                            </span>
                        </label>
                    </div>

                    <!-- BAYAR LANGSUNG (QRIS via Midtrans): value="0" → di PHP !empty("0") = false,
                         sehingga dompet_bayar_pesanan(...) menerima false (saldo tidak dipakai) -->
                    <div class="by-opt">
                        <input class="by-opt__input" type="radio" id="bayar-qris" name="pakai_saldo" value="0">
                        <label class="by-opt__row" for="bayar-qris">
                            <span class="by-opt__mark" aria-hidden="true"></span>
                            <span>Bayar langsung menggunakan Qris</span>
                        </label>
                    </div>
                </div>

                <div class="by-actions">
                    <button type="submit" class="by-submit">
                        <img src="/assets/img/dompet-ic-card-white.png" alt="">
                        <span>Lanjut Bayar</span>
                        <svg viewBox="0 0 26 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 8h22M17 2l7 6-7 6"/></svg>
                    </button>
                </div>
            </form>
        </div>
    </section>

</div>
</main>
</body>
</html>
    <?php
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !csrf_validate()) {
    set_flash("gagal", "Sesi tidak valid. Silakan coba lagi.");
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

[$ok, $tipe, $hasil] = dompet_bayar_pesanan(
    $pdo, $id, $gross, $jenis,
    $_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["pakai_saldo"])
);

if (!$ok) {
    set_flash("gagal", $hasil);
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

if ($tipe === "lunas") {
    refresh_mv_rekap($pdo);
    set_flash("sukses", "Pembayaran berhasil dari saldo dompet.");
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

// Lompat ke halaman pembayaran Midtrans
redirect($hasil);