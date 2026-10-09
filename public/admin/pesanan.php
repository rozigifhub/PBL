<?php

/**
 * Admin — daftar semua pesanan (dengan filter status).
 * Klik nomor pesanan untuk detail: ubah status & kelola pengiriman.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

$daftarStatus = [
    "Menunggu Pembayaran", "Menunggu DP", "DP Lunas", "Lunas",
    "Diproses", "Dikirim", "Selesai", "Dibatalkan",
];

$statusFilter = in_array($_GET["status"] ?? "", $daftarStatus, true)
    ? $_GET["status"]
    : "";

$sql = "
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga, p.status_pesanan,
           a.username, m.nama AS nama_mhs, m.nim,
           (SELECT COALESCE(SUM(d.jumlah), 0)
            FROM detail_pesanan d
            WHERE d.id_pesanan = p.id_pesanan) AS total_item,
           (SELECT pb.status_bayar
            FROM pembayaran pb
            WHERE pb.id_pesanan = p.id_pesanan
            ORDER BY pb.id_pembayaran DESC
            LIMIT 1) AS status_bayar
    FROM pesanan p
    JOIN akun_login a ON a.id_akun = p.id_akun
    LEFT JOIN mahasiswa m ON m.id_akun = p.id_akun
";
$params = [];
if ($statusFilter !== "") {
    $sql .= " WHERE p.status_pesanan = :status";
    $params[":status"] = $statusFilter;
}
$sql .= " ORDER BY p.id_pesanan DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pesanan = $stmt->fetchAll();

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Pesanan - Admin</title>
</head>
<body>

<main>
    <h1>Kelola Pesanan</h1>
    <p>
        <a href="dashboard.php">&larr; Dashboard</a> |
        <a href="kategori.php">Kategori</a> |
        <a href="merchandise.php">Merchandise</a>
    </p>

    <?php if ($flash): ?>
        <p><b><?= e($flash["msg"]) ?></b></p>
    <?php endif; ?>

    <!-- Filter status -->
    <p>
        <a href="pesanan.php"><b>Semua</b></a>
        <?php foreach ($daftarStatus as $s): ?>
            | <a href="pesanan.php?status=<?= e($s) ?>"><?= e($s) ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!$pesanan): ?>
        <p>Tidak ada pesanan<?= $statusFilter !== "" ? " dengan status tersebut." : "." ?></p>
    <?php else: ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>No.</th>
                <th>Tanggal</th>
                <th>Pembeli</th>
                <th>Item</th>
                <th>Total</th>
                <th>Status Pesanan</th>
                <th>Pembayaran</th>
            </tr>
            <?php foreach ($pesanan as $p): ?>
                <tr>
                    <td>
                        <a href="pesanan-detail.php?id=<?= (int)$p["id_pesanan"] ?>">
                            <b>#<?= (int)$p["id_pesanan"] ?></b>
                        </a>
                    </td>
                    <td><?= e(substr($p["tanggal_pesanan"], 0, 16)) ?></td>
                    <td>
                        <?= e($p["nama_mhs"] ?? $p["username"]) ?><br>
                        <small><?= e($p["nim"] ?? "-") ?></small>
                    </td>
                    <td><?= (int)$p["total_item"] ?></td>
                    <td>Rp <?= number_format((float)$p["total_harga"], 0, ",", ".") ?></td>
                    <td><?= e($p["status_pesanan"]) ?></td>
                    <td><?= e($p["status_bayar"] ?? "-") ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>

</main>

</body>
</html>
