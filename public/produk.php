<?php

// Detail produk publik: produk.php?id=ID

require_once __DIR__ . "/../config/functions.php";
app_session_start();
require_once __DIR__ . "/../config/database.php";

$id = (int)($_GET["id"] ?? 0);

$stmt = $pdo->prepare("
    SELECT m.id_merchandise, m.nama_merchandise, m.ukuran, m.harga, m.stok, m.foto,
           k.id_kategori, k.nama_kategori
    FROM merchandise m
    JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
    WHERE m.id_merchandise = :id
");
$stmt->execute([":id" => $id]);
$produk = $stmt->fetch();

$fotoUrl = "";
if ($produk !== false) {
    $namaFoto = basename($produk["foto"]);
    $fotoUrl  = is_file(__DIR__ . "/uploads/merchandise/" . $namaFoto)
        ? "uploads/merchandise/" . $namaFoto
        : "";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $produk !== false ? e($produk["nama_merchandise"]) . " - Merchandise Kampus" : "Produk tidak ditemukan" ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/includes/navbar.php"; ?>

<main>
    <?php if ($produk === false): ?>

        <h1>Produk tidak ditemukan</h1>
        <p>Produk mungkin sudah dihapus atau tautannya salah.</p>
        <p><a href="index.php">&larr; Kembali ke Katalog</a></p>

    <?php else: ?>

        <p><a href="index.php">&larr; Kembali ke Katalog</a></p>

        <h1><?= e($produk["nama_merchandise"]) ?></h1>

        <p>
            <?php if ($fotoUrl !== ""): ?>
                <img src="<?= e($fotoUrl) ?>" alt="<?= e($produk["nama_merchandise"]) ?>" width="280">
            <?php else: ?>
                <i>(tanpa foto)</i>
            <?php endif; ?>
        </p>

        <table cellpadding="6">
            <tr>
                <td><b>Kategori</b></td>
                <td><a href="index.php?kategori=<?= (int)$produk["id_kategori"] ?>"><?= e($produk["nama_kategori"]) ?></a></td>
            </tr>
            <tr>
                <td><b>Ukuran</b></td>
                <td><?= e($produk["ukuran"]) ?></td>
            </tr>
            <tr>
                <td><b>Harga</b></td>
                <td><b>Rp <?= number_format((float)$produk["harga"], 0, ",", ".") ?></b></td>
            </tr>
            <tr>
                <td><b>Stok</b></td>
                <td>
                    <?php if ((int)$produk["stok"] > 0): ?>
                        Tersedia (<?= (int)$produk["stok"] ?>)
                    <?php else: ?>
                        Habis
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <?php if ((int)$produk["stok"] > 0): ?>
            <!-- TODO fase 3: tombol ini dihubungkan ke fitur keranjang/checkout -->
            <p><button type="button" disabled>Tambah ke Keranjang (segera)</button></p>
        <?php endif; ?>

    <?php endif; ?>
</main>

</body>
</html>
