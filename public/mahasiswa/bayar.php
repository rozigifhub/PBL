<?php

/**
 * Bayar pesanan: buat transaksi Snap lalu arahkan browser
 * ke halaman pembayaran Midtrans.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_mahasiswa();

$id = (int)($_GET["id"] ?? 0);

// Pesanan harus ada, milik sendiri, dan masih menunggu pembayaran
$stmt = $pdo->prepare("
    SELECT id_pesanan, total_harga, status_pesanan
    FROM pesanan
    WHERE id_pesanan = :id AND id_akun = :id_akun
");
$stmt->execute([":id" => $id, ":id_akun" => $_SESSION["id_akun"]]);
$pesanan = $stmt->fetch();

if ($pesanan === false) {
    redirect("/mahasiswa/riwayat.php");
}

if ($pesanan["status_pesanan"] !== "Menunggu Pembayaran") {
    set_flash("gagal", "Pesanan ini tidak dapat dibayar (status: {$pesanan["status_pesanan"]}).");
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

[$ok, $hasil] = midtrans_buat_pembayaran($pdo, (int)$pesanan["id_pesanan"], (float)$pesanan["total_harga"]);

if (!$ok) {
    set_flash("gagal", $hasil);
    redirect("/mahasiswa/pesanan.php?id=" . $id);
}

// Lompat ke halaman pembayaran Midtrans
redirect($hasil);
