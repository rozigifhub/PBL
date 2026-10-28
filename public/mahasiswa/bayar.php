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
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>
<main>
    <h1>Bayar Pesanan #<?= $id ?></h1>
    <p><a href="/mahasiswa/pesanan.php?id=<?= $id ?>">&larr; Kembali ke Pesanan</a></p>

    <p>Tagihan (<?= e($jenis) ?>): <b>Rp <?= number_format($gross, 0, ",", ".") ?></b></p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <p>
            <label>
                <input type="checkbox" name="pakai_saldo" value="1" checked>
                Gunakan saldo dompet (tersedia Rp <?= number_format($saldo, 0, ",", ".") ?>)
            </label><br>
            <small>
                <?php if ($saldo >= $gross): ?>
                    Saldo cukup — tagihan lunas dari dompet tanpa pembayaran online.
                <?php else: ?>
                    Saldo Rp <?= number_format($pakai, 0, ",", ".") ?> dipakai, sisa
                    Rp <?= number_format($gross - $pakai, 0, ",", ".") ?> dibayar lewat Midtrans.
                <?php endif; ?>
            </small>
        </p>
        <button type="submit"><b>Lanjut Bayar</b></button>
    </form>
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
