<?php

/**
 * Keranjang belanja berbasis session.
 *
 * Struktur $_SESSION["keranjang"]:
 *   [id_merchandise => ["jumlah" => int], ...]
 *
 * Harga & nama SELALU diambil dari database saat ditampilkan —
 * snapshot harga (harga_satuan) baru dikunci saat checkout (fase 3B).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action    = $_POST["action"] ?? "";
        $id        = (int)($_POST["id_merchandise"] ?? 0);
        $keranjang = $_SESSION["keranjang"] ?? [];

        // Hapus satu item
        if ($action === "hapus") {
            unset($keranjang[$id]);
            $_SESSION["keranjang"] = $keranjang;
            set_flash("sukses", "Item dihapus dari keranjang.");
            redirect("/mahasiswa/keranjang.php");
        }

        // Kosongkan seluruh keranjang
        if ($action === "kosongkan") {
            unset($_SESSION["keranjang"]);
            set_flash("sukses", "Keranjang dikosongkan.");
            redirect("/mahasiswa/keranjang.php");
        }

        // Perbarui jumlah satu item (dengan clamp ke stok terkini)
        if ($action === "update") {
            $jumlah = (int)($_POST["jumlah"] ?? 1);

            $stmt = $pdo->prepare("SELECT stok FROM merchandise WHERE id_merchandise = :id");
            $stmt->execute([":id" => $id]);
            $stok = $stmt->fetchColumn();

            if ($stok === false) {
                unset($keranjang[$id]);
                $_SESSION["keranjang"] = $keranjang;
                set_flash("gagal", "Produk sudah tidak tersedia dan dihapus dari keranjang.");
            } elseif ($jumlah < 1) {
                unset($keranjang[$id]);
                $_SESSION["keranjang"] = $keranjang;
                set_flash("sukses", "Item dihapus dari keranjang.");
            } elseif ($jumlah > (int)$stok) {
                $keranjang[$id]["jumlah"] = (int)$stok;
                $_SESSION["keranjang"] = $keranjang;
                set_flash("gagal", "Stok tidak cukup — jumlah disesuaikan menjadi {$stok}.");
            } else {
                $keranjang[$id]["jumlah"] = $jumlah;
                $_SESSION["keranjang"] = $keranjang;
                set_flash("sukses", "Jumlah diperbarui.");
            }
            redirect("/mahasiswa/keranjang.php");
        }
    }
}

// ---------- Susun data keranjang untuk ditampilkan ----------

$keranjang = $_SESSION["keranjang"] ?? [];
$items = [];
$total = 0.0;

foreach ($keranjang as $id => $item) {
    $stmt = $pdo->prepare("
        SELECT m.id_merchandise, m.nama_merchandise, m.harga, m.stok, m.foto, k.nama_kategori
        FROM merchandise m
        JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
        WHERE m.id_merchandise = :id
    ");
    $stmt->execute([":id" => (int)$id]);
    $p = $stmt->fetch();

    if ($p === false) {
        // Produk sudah dihapus admin — buang dari keranjang
        unset($_SESSION["keranjang"][$id]);
        continue;
    }

    $jumlah     = max(1, (int)($item["jumlah"] ?? 1));
    $stokKurang = $jumlah > (int)$p["stok"];
    $subtotal   = (float)$p["harga"] * $jumlah;

    $items[] = [
        "id"          => (int)$id,
        "nama"        => $p["nama_merchandise"],
        "kategori"    => $p["nama_kategori"],
        "harga"       => (float)$p["harga"],
        "stok"        => (int)$p["stok"],
        "foto"        => $p["foto"],
        "jumlah"      => $jumlah,
        "subtotal"    => $subtotal,
        "stok_kurang" => $stokKurang,
    ];
    $total += $subtotal;
}
$_SESSION["keranjang"] = $keranjang; // simpan hasil pembersihan otomatis

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keranjang - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<main>
    <h1>Keranjang Belanja</h1>
    <p><a href="/mahasiswa/katalog.php">&larr; Lanjut belanja</a></p>

    <?php if ($flash): ?>
        <p style="color:<?= $flash["type"] === "sukses" ? "#0a7d32" : "#b00020" ?>;">
            <b><?= e($flash["msg"]) ?></b>
        </p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p style="color:#b00020;"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <?php if (!$items): ?>

        <p>Keranjang masih kosong.</p>
        <p><a href="/mahasiswa/katalog.php"><b>Lihat Katalog &rarr;</b></a></p>

    <?php else: ?>

        <?php $adaStokKurang = false; ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Produk</th>
                <th>Harga</th>
                <th>Jumlah</th>
                <th>Subtotal</th>
                <th>Aksi</th>
            </tr>
            <?php foreach ($items as $item): ?>
                <?php if ($item["stok_kurang"]) $adaStokKurang = true; ?>
                <tr>
                    <td>
                        <b><?= e($item["nama"]) ?></b><br>
                        <small><?= e($item["kategori"]) ?></small>
                    </td>
                    <td>Rp <?= number_format($item["harga"], 0, ",", ".") ?></td>
                    <td>
                        <?php if ($item["stok_kurang"]): ?>
                            <span style="color:#b00020;"><b>Stok tidak cukup (tersedia <?= $item["stok"] ?>)</b></span>
                        <?php endif; ?>
                        <form method="POST" style="display:flex;gap:6px;align-items:center;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id_merchandise" value="<?= $item["id"] ?>">
                            <input type="number" name="jumlah" value="<?= $item["jumlah"] ?>"
                                   min="1" max="<?= $item["stok"] ?>" style="width:70px;" required>
                            <button type="submit">Perbarui</button>
                        </form>
                    </td>
                    <td>Rp <?= number_format($item["subtotal"], 0, ",", ".") ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Hapus item ini?')">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="hapus">
                            <input type="hidden" name="id_merchandise" value="<?= $item["id"] ?>">
                            <button type="submit">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3" align="right"><b>Total</b></td>
                <td colspan="2"><b>Rp <?= number_format($total, 0, ",", ".") ?></b></td>
            </tr>
        </table>

        <?php if ($adaStokKurang): ?>
            <p style="color:#b00020;">Perbarui jumlah item bertanda merah sebelum melanjutkan ke checkout.</p>
        <?php endif; ?>

        <p>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Kosongkan keranjang?')">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="kosongkan">
                <button type="submit">Kosongkan Keranjang</button>
            </form>
            |
            <a href="/mahasiswa/checkout.php"><b>Lanjut ke Checkout &rarr;</b></a>
        </p>

    <?php endif; ?>

</main>

</body>
</html>
