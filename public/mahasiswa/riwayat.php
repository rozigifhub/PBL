<?php

/**
 * Riwayat pesanan mahasiswa (terbaru dulu).
 * Tiap baris menuju detail: pesanan.php?id=...
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

$stmt = $pdo->prepare("
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga, p.status_pesanan, p.tipe_pesanan,
           (SELECT COALESCE(SUM(d.jumlah), 0)
            FROM detail_pesanan d
            WHERE d.id_pesanan = p.id_pesanan) AS total_item,
           (SELECT pb.status_bayar
            FROM pembayaran pb
            WHERE pb.id_pesanan = p.id_pesanan
            ORDER BY pb.id_pembayaran DESC
            LIMIT 1) AS status_bayar
    FROM pesanan p
    WHERE p.id_akun = :id_akun
    ORDER BY p.id_pesanan DESC
");
$stmt->execute([":id_akun" => $_SESSION["id_akun"]]);
$pesanan = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riwayat Pesanan - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<main>
    <h1>Riwayat Pesanan</h1>
    <p>
        <a href="/mahasiswa/dashboard.php">&larr; Dashboard</a> |
        <a href="/mahasiswa/katalog.php">Katalog</a>
    </p>

    <?php if (!$pesanan): ?>

        <p>Belum ada pesanan.</p>
        <p><a href="/mahasiswa/katalog.php"><b>Mulai Belanja &rarr;</b></a></p>

    <?php else: ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>No. Pesanan</th>
                <th>Tanggal</th>
                <th>Item</th>
                <th>Total</th>
                <th>Status</th>
            </tr>
            <?php foreach ($pesanan as $p): ?>
                <tr>
                    <td>
                        <a href="/mahasiswa/pesanan.php?id=<?= (int)$p["id_pesanan"] ?>">
                            <b>#<?= (int)$p["id_pesanan"] ?></b>
                        </a>
                    </td>
                    <td><?= e(substr($p["tanggal_pesanan"], 0, 16)) ?></td>
                    <td><?= (int)$p["total_item"] ?></td>
                    <td>Rp <?= number_format((float)$p["total_harga"], 0, ",", ".") ?></td>
                    <td>
                        <?php if ($p["status_pesanan"] === "Menunggu Pembayaran"): ?>
                            <span style="color:#b00020;"><b>⏳ Belum dibayar</b></span>
                        <?php else: ?>
                            <?= e($p["status_pesanan"]) ?>
                        <?php endif; ?>
                        <?php if (!empty($p["status_bayar"])): ?>
                            <br><small>Pembayaran: <?= e($p["status_bayar"]) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>

</main>

</body>
</html>
