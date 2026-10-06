<?php

/**
 * Admin — CRUD kategori merchandise.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

$error   = "";
$editRow = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action = $_POST["action"] ?? "";
        $nama   = trim($_POST["nama_kategori"] ?? "");

        try {
            if ($nama === "") {
                $error = "Nama kategori wajib diisi.";
            } elseif (strlen($nama) > 100) {
                $error = "Nama kategori maksimal 100 karakter.";
            } elseif ($action === "create") {
                $stmt = $pdo->prepare("INSERT INTO kategori_merchandise (nama_kategori) VALUES (:nama)");
                $stmt->execute([":nama" => $nama]);
                set_flash("sukses", "Kategori \"{$nama}\" ditambahkan.");
                redirect("kategori.php");
            } elseif ($action === "update") {
                $id = (int)($_POST["id_kategori"] ?? 0);
                $stmt = $pdo->prepare("UPDATE kategori_merchandise SET nama_kategori = :nama WHERE id_kategori = :id");
                $stmt->execute([":nama" => $nama, ":id" => $id]);
                set_flash("sukses", "Kategori diperbarui.");
                redirect("kategori.php");
            } elseif ($action === "delete") {
                $id = (int)($_POST["id_kategori"] ?? 0);
                try {
                    $stmt = $pdo->prepare("DELETE FROM kategori_merchandise WHERE id_kategori = :id");
                    $stmt->execute([":id" => $id]);
                    set_flash("sukses", "Kategori dihapus.");
                    redirect("kategori.php");
                } catch (PDOException $e) {
                    // FK RESTRICT: kategori masih dipakai produk
                    $error = "Kategori tidak bisa dihapus karena masih dipakai produk.";
                }
            }
        } catch (PDOException $e) {
            error_log("CRUD kategori gagal: " . $e->getMessage());
            $error = str_contains($e->getMessage(), "duplicate key")
                ? "Nama kategori sudah dipakai."
                : "Terjadi kesalahan. Coba lagi.";
        }
    }
}

// Mode edit: ?edit=ID
if ($error === "" && isset($_GET["edit"])) {
    $stmt = $pdo->prepare("SELECT * FROM kategori_merchandise WHERE id_kategori = :id");
    $stmt->execute([":id" => (int)$_GET["edit"]]);
    $editRow = $stmt->fetch();
}

$kategori = $pdo->query("
    SELECT k.id_kategori, k.nama_kategori, COUNT(m.id_merchandise) AS jumlah_produk
    FROM kategori_merchandise k
    LEFT JOIN merchandise m ON m.id_kategori = k.id_kategori
    GROUP BY k.id_kategori, k.nama_kategori
    ORDER BY k.id_kategori
")->fetchAll();

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Kategori - Admin</title>
</head>
<body>

    <h1>Kelola Kategori Merchandise</h1>
    <p><a href="dashboard.php">&larr; Dashboard</a></p>

    <?php if ($flash): ?>
        <p><b><?= e($flash["msg"]) ?></b></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p style="color:#b00020;"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editRow ? "update" : "create" ?>">
        <?php if ($editRow): ?>
            <input type="hidden" name="id_kategori" value="<?= (int)$editRow["id_kategori"] ?>">
        <?php endif; ?>

        <label>Nama Kategori</label><br>
        <input type="text" name="nama_kategori" required maxlength="100"
               value="<?= e($editRow["nama_kategori"] ?? "") ?>">
        <button type="submit"><?= $editRow ? "Simpan Perubahan" : "Tambah Kategori" ?></button>
        <?php if ($editRow): ?>
            <a href="kategori.php">Batal edit</a>
        <?php endif; ?>
    </form>

    <hr>

    <table border="1" cellpadding="6" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Nama Kategori</th>
            <th>Jumlah Produk</th>
            <th>Aksi</th>
        </tr>
        <?php foreach ($kategori as $k): ?>
            <tr>
                <td><?= (int)$k["id_kategori"] ?></td>
                <td><?= e($k["nama_kategori"]) ?></td>
                <td><?= (int)$k["jumlah_produk"] ?></td>
                <td>
                    <a href="kategori.php?edit=<?= (int)$k["id_kategori"] ?>">Edit</a>
                    |
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Hapus kategori ini?')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id_kategori" value="<?= (int)$k["id_kategori"] ?>">
                        <button type="submit">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$kategori): ?>
            <tr><td colspan="4">Belum ada kategori.</td></tr>
        <?php endif; ?>
    </table>

</body>
</html>
