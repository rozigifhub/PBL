<?php

// Katalog publik: daftar merchandise dengan filter kategori.

require_once __DIR__ . "/../config/functions.php";
app_session_start();
require_once __DIR__ . "/../config/database.php";

// Filter kategori (opsional): index.php?kategori=ID
$idKat = (int)($_GET["kategori"] ?? 0);

$kategori = $pdo->query("
    SELECT id_kategori, nama_kategori
    FROM kategori_merchandise
    ORDER BY nama_kategori
")->fetchAll();

$sql = "
    SELECT m.id_merchandise, m.nama_merchandise, m.ukuran, m.harga, m.stok, m.foto,
           k.id_kategori, k.nama_kategori
    FROM merchandise m
    JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
";
$params = [];
if ($idKat > 0) {
    $sql .= " WHERE k.id_kategori = :id_kategori";
    $params[":id_kategori"] = $idKat;
}
$sql .= " ORDER BY k.nama_kategori, m.nama_merchandise";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$produk = $stmt->fetchAll();

// Nama kategori terpilih, untuk judul halaman
$namaKategori = "";
foreach ($kategori as $k) {
    if ((int)$k["id_kategori"] === $idKat) {
        $namaKategori = $k["nama_kategori"];
        break;
    }
}

/** URL foto produk; kembalikan string kosong jika file tidak ada. */
function foto_url(string $foto): string
{
    $nama = basename($foto);
    return is_file(__DIR__ . "/uploads/merchandise/" . $nama)
        ? "uploads/merchandise/" . $nama
        : "";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Katalog Merchandise Kampus</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/includes/navbar.php"; ?>

<main>
    <h1>Katalog Merchandise<?= $namaKategori !== "" ? " — " . e($namaKategori) : "" ?></h1>

    <?php if (empty($_SESSION["id_akun"])): ?>
        <p>
            <a href="login.php">Login</a> |
            <a href="register.php">Register</a>
        </p>
    <?php else: ?>
        <p>
            Halo, <?= e($_SESSION["username"]) ?> (<?= e($_SESSION["role"]) ?>)
            · <a href="logout.php">Logout</a>
            <?php if ($_SESSION["role"] === "Admin"): ?>
                · <a href="admin/dashboard.php">Dashboard Admin</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <!-- Filter kategori -->
    <p>
        <a href="index.php"><b>Semua</b></a>
        <?php foreach ($kategori as $k): ?>
            | <a href="index.php?kategori=<?= (int)$k["id_kategori"] ?>"><?= e($k["nama_kategori"]) ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!$produk): ?>

        <p>Belum ada produk<?= $namaKategori !== "" ? " pada kategori \"" . e($namaKategori) . "\"" : "" ?> untuk saat ini.</p>

    <?php else: ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Foto</th>
                <th>Nama</th>
                <th>Kategori</th>
                <th>Ukuran</th>
                <th>Harga</th>
                <th>Stok</th>
            </tr>
            <?php foreach ($produk as $p): ?>
                <?php $foto = foto_url($p["foto"]); ?>
                <tr>
                    <td>
                        <?php if ($foto !== ""): ?>
                            <img src="<?= e($foto) ?>" alt="<?= e($p["nama_merchandise"]) ?>" width="70">
                        <?php else: ?>
                            <i>(tanpa foto)</i>
                        <?php endif; ?>
                    </td>
                    <td><a href="produk.php?id=<?= (int)$p["id_merchandise"] ?>"><?= e($p["nama_merchandise"]) ?></a></td>
                    <td><?= e($p["nama_kategori"]) ?></td>
                    <td><?= e($p["ukuran"]) ?></td>
                    <td>Rp <?= number_format((float)$p["harga"], 0, ",", ".") ?></td>
                    <td>
                        <?php if ((int)$p["stok"] > 0): ?>
                            Tersedia (<?= (int)$p["stok"] ?>)
                        <?php else: ?>
                            Habis
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>

</main>

</body>
</html>
