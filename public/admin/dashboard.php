<?php

// Dashboard admin: navigasi kelola + statistik dari materialized view.

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

// Top 5 produk terlaris — dibaca dari materialized view (cepat,
// data statistik yang diperbolehkan sedikit basi).
$terlaris = [];
try {
    $terlaris = $pdo->query("
        SELECT nama_merchandise, nama_kategori, jumlah_terjual, total_pendapatan
        FROM mv_rekap_penjualan_produk
        WHERE jumlah_terjual > 0
        ORDER BY jumlah_terjual DESC, total_pendapatan DESC
        LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("Gagal membaca mv_rekap_penjualan_produk: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Admin - Merchandise Kampus</title>
</head>
<body>

<main>
    <h1>Dashboard Admin</h1>

    <p>
        Selamat datang,
        <b><?= e($_SESSION["username"]) ?></b>
    </p>

    <p>
        <a href="kategori.php">Kelola Kategori</a> |
        <a href="merchandise.php">Kelola Merchandise</a> |
        <a href="pesanan.php">Kelola Pesanan</a> |
        <a href="/logout.php">Logout</a>
    </p>

    <h2>Produk Terlaris</h2>

    <?php if ($terlaris): ?>
        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Produk</th>
                <th>Kategori</th>
                <th>Terjual</th>
                <th>Pendapatan</th>
            </tr>
            <?php foreach ($terlaris as $t): ?>
                <tr>
                    <td><?= e($t["nama_merchandise"]) ?></td>
                    <td><?= e($t["nama_kategori"]) ?></td>
                    <td><?= (int)$t["jumlah_terjual"] ?></td>
                    <td>Rp <?= number_format((float)$t["total_pendapatan"], 0, ",", ".") ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p><small>Rekap penjualan hanya dihitung dari pesanan yang sah (DP Lunas, Lunas, Diproses, Dikirim, Selesai).</small></p>
    <?php else: ?>
        <p>Belum ada penjualan.</p>
    <?php endif; ?>

</main>

</body>
</html>
