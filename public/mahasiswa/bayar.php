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

[$ok, $hasil] = midtrans_buat_pembayaran($pdo, $id, $gross, $jenis);

if (!$ok) {
    set_flash("gagal", $hasil);
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

// Lompat ke halaman pembayaran Midtrans
redirect($hasil);
