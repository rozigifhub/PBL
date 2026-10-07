<?php

/**
 * Detail pesanan milik mahasiswa (dipakai juga sebagai halaman
 * konfirmasi setelah checkout: pesanan.php?id=X&baru=1).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

$id   = (int)($_GET["id"] ?? 0);
$baru = isset($_GET["baru"]);

// Callback Midtrans mengirim order_id (format: PBL-00001-<timestamp>), bukan id.
// Bila parameter id tidak ada, ambil id pesanan dari situ.
if ($id === 0 && !empty($_GET["order_id"])
    && preg_match('/^PBL-(\d+)-/', (string)$_GET["order_id"], $m)) {
    $id = (int)$m[1];
}

// Status pembayaran terakhir pesanan ini
$stmt = $pdo->prepare("
    SELECT status_bayar, payment_url
    FROM pembayaran
    WHERE id_pesanan = :id
    ORDER BY id_pembayaran DESC
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$pembayaran = $stmt->fetch();

$flash = take_flash();

// Pastikan pesanan ada DAN milik mahasiswa yang sedang login
$stmt = $pdo->prepare("
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga,
           p.status_pesanan, p.tipe_pesanan,
           g.alamat, g.status_pengiriman, g.tanggal_pengiriman
    FROM pesanan p
    LEFT JOIN pengiriman g ON g.id_pesanan = p.id_pesanan
    WHERE p.id_pesanan = :id AND p.id_akun = :id_akun
");
$stmt->execute([":id" => $id, ":id_akun" => $_SESSION["id_akun"]]);
$pesanan = $stmt->fetch();

$detail = [];
if ($pesanan !== false) {
    $stmt = $pdo->prepare("
        SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama_merchandise
        FROM detail_pesanan d
        JOIN merchandise m ON m.id_merchandise = d.id_merchandise
        WHERE d.id_pesanan = :id
        ORDER BY d.id_detail
    ");
    $stmt->execute([":id" => $id]);
    $detail = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pesanan #<?= $id ?> - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<main>
    <?php if ($pesanan === false): ?>

        <h1>Pesanan tidak ditemukan</h1>
        <p><a href="/mahasiswa/katalog.php">&larr; Kembali ke Katalog</a></p>

    <?php else: ?>

        <?php if ($flash): ?>
            <p style="color:<?= $flash["type"] === "sukses" ? "#0a7d32" : "#b00020" ?>;">
                <b><?= e($flash["msg"]) ?></b>
            </p>
        <?php endif; ?>

        <?php if ($baru): ?>
            <p style="color:#0a7d32;"><b>✅ Pesanan berhasil dibuat! Simpan nomor pesanan di bawah ini.</b></p>
        <?php endif; ?>

        <h1>Pesanan #<?= (int)$pesanan["id_pesanan"] ?></h1>

        <p><a href="/mahasiswa/katalog.php">Lanjut belanja</a> | <a href="/mahasiswa/riwayat.php">Riwayat Pesanan</a> | <a href="/mahasiswa/dashboard.php">Dashboard</a></p>

        <table cellpadding="6">
            <tr>
                <td><b>Tanggal Pesanan</b></td>
                <td><?= e(substr($pesanan["tanggal_pesanan"], 0, 16)) ?></td>
            </tr>
            <tr>
                <td><b>Status Pesanan</b></td>
                <td><b><?= e($pesanan["status_pesanan"]) ?></b></td>
            </tr>
            <tr>
                <td><b>Status Pembayaran</b></td>
                <td><?= e($pembayaran["status_bayar"] ?? "Belum ada transaksi") ?></td>
            </tr>
            <tr>
                <td><b>Tipe</b></td>
                <td><?= e($pesanan["tipe_pesanan"]) ?></td>
            </tr>
            <tr>
                <td><b>Alamat Pengiriman</b></td>
                <td><?= nl2br(e($pesanan["alamat"] ?? "-")) ?></td>
            </tr>
            <tr>
                <td><b>Status Pengiriman</b></td>
                <td><?= e($pesanan["status_pengiriman"] ?? "Belum Dikirim") ?></td>
            </tr>
        </table>

        <h2>Item Pesanan</h2>

        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Produk</th>
                <th>Harga Satuan</th>
                <th>Jumlah</th>
                <th>Subtotal</th>
            </tr>
            <?php foreach ($detail as $d): ?>
                <tr>
                    <td><?= e($d["nama_merchandise"]) ?></td>
                    <td>Rp <?= number_format((float)$d["harga_satuan"], 0, ",", ".") ?></td>
                    <td><?= (int)$d["jumlah"] ?></td>
                    <td>Rp <?= number_format((float)$d["subtotal"], 0, ",", ".") ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3" align="right"><b>Total</b></td>
                <td><b>Rp <?= number_format((float)$pesanan["total_harga"], 0, ",", ".") ?></b></td>
            </tr>
        </table>

        <?php if ($pesanan["status_pesanan"] === "Menunggu Pembayaran"): ?>
            <p>
                <a href="/mahasiswa/bayar.php?id=<?= (int)$pesanan["id_pesanan"] ?>"><b>Bayar Sekarang &rarr;</b></a>
                <?php if (($pembayaran["status_bayar"] ?? "") === "Menunggu" && !empty($pembayaran["payment_url"])): ?>
                    | <a href="<?= e($pembayaran["payment_url"]) ?>">Lanjutkan pembayaran sebelumnya</a>
                <?php endif; ?>
            </p>
            <p><i>Pembayaran online (Midtrans) — selesaikan sebelum tautan kedaluwarsa.</i></p>
        <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>
