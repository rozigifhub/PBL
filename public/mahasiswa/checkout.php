<?php

/**
 * Checkout — fase 3B.
 *
 * Saat ini hanya menampilkan ringkasan pesanan.
 * Pada fase berikutnya halaman ini akan:
 *   - memvalidasi stok semua item,
 *   - memotong stok,
 *   - membuat pesanan + detail_pesanan (dengan harga_satuan snapshot)
 *     di dalam satu transaksi database,
 *   - lalu membuat pembayaran via Midtrans (fase 4).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

$keranjang = $_SESSION["keranjang"] ?? [];
if (empty($keranjang)) {
    redirect("/mahasiswa/keranjang.php");
}

$items = [];
$total = 0.0;

foreach ($keranjang as $id => $item) {
    $stmt = $pdo->prepare("
        SELECT m.id_merchandise, m.nama_merchandise, m.harga, m.stok, k.nama_kategori
        FROM merchandise m
        JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
        WHERE m.id_merchandise = :id
    ");
    $stmt->execute([":id" => (int)$id]);
    $p = $stmt->fetch();

    if ($p === false) {
        unset($_SESSION["keranjang"][$id]);
        continue;
    }

    $jumlah   = max(1, (int)($item["jumlah"] ?? 1));
    $subtotal = (float)$p["harga"] * $jumlah;

    $items[] = [
        "id"       => (int)$id,
        "nama"     => $p["nama_merchandise"],
        "kategori" => $p["nama_kategori"],
        "harga"    => (float)$p["harga"],
        "jumlah"   => $jumlah,
        "subtotal" => $subtotal,
    ];
    $total += $subtotal;
}
$_SESSION["keranjang"] = $keranjang;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<main>
    <h1>Checkout</h1>
    <p><a href="/mahasiswa/keranjang.php">&larr; Kembali ke Keranjang</a></p>

    <table border="1" cellpadding="8" cellspacing="0">
        <tr>
            <th>Produk</th>
            <th>Harga</th>
            <th>Jumlah</th>
            <th>Subtotal</th>
        </tr>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item["nama"]) ?> <small>(<?= e($item["kategori"]) ?>)</small></td>
                <td>Rp <?= number_format($item["harga"], 0, ",", ".") ?></td>
                <td><?= $item["jumlah"] ?></td>
                <td>Rp <?= number_format($item["subtotal"], 0, ",", ".") ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="3" align="right"><b>Total Pembayaran</b></td>
            <td><b>Rp <?= number_format($total, 0, ",", ".") ?></b></td>
        </tr>
    </table>

    <!-- TODO fase 3B: proses pesanan (validasi stok + transaksi INSERT) -->
    <!-- TODO fase 4  : tombol ini diganti alur pembayaran Midtrans -->
    <p>
        <button type="button" disabled>Proses Pesanan (segera)</button>
    </p>

</main>

</body>
</html>
