<?php

/**
 * Admin — CRUD merchandise (dengan upload foto).
 *
 * Keamanan upload:
 *  - tipe file diverifikasi dari ISI file (finfo), bukan dari nama/ekstensi
 *  - hanya jpeg / png / webp
 *  - nama file diacak (mencegah overwrite & path traversal)
 *  - ukuran maksimal 2 MB
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

const UPLOAD_DIR   = __DIR__ . "/../uploads/merchandise";
const UPLOAD_URL   = "uploads/merchandise";
const MAX_FOTO_BYTE = 2 * 1024 * 1024; // 2 MB

const MIME_DIIZINKAN = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
];

/**
 * Validasi + pindahkan file upload.
 * Return: [ok => bool, "filename" | "pesan error"]
 */
function proses_upload(array $file): array
{
    $kode = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);

    if ($kode !== UPLOAD_ERR_OK) {
        $pesan = match ($kode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Ukuran foto melebihi batas yang diizinkan server.",
            UPLOAD_ERR_PARTIAL => "Upload terputus. Silakan coba lagi.",
            UPLOAD_ERR_NO_FILE => "Foto wajib diunggah.",
            default => "Upload foto gagal (kode error {$kode}).",
        };
        return [false, $pesan];
    }

    if (($file["size"] ?? 0) <= 0 || $file["size"] > MAX_FOTO_BYTE) {
        return [false, "Ukuran foto maksimal 2 MB."];
    }

    // Verifikasi MIME dari ISI file, bukan dari nama/ekstensi
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file["tmp_name"]);

    if (!isset(MIME_DIIZINKAN[$mime])) {
        return [false, "Format foto harus JPG, PNG, atau WEBP."];
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true)) {
        return [false, "Gagal menyiapkan folder upload."];
    }

    $namaBaru = bin2hex(random_bytes(8)) . "." . MIME_DIIZINKAN[$mime];

    if (!move_uploaded_file($file["tmp_name"], UPLOAD_DIR . "/" . $namaBaru)) {
        return [false, "Gagal menyimpan foto."];
    }

    return [true, $namaBaru];
}

/** Hapus file foto lama (basename untuk cegah path traversal). */
function hapus_foto(?string $filename): void
{
    if ($filename === null || $filename === "") {
        return;
    }
    $path = UPLOAD_DIR . "/" . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

$error = "";
$editRow = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action  = $_POST["action"] ?? "";
        $id      = (int)($_POST["id_merchandise"] ?? 0);
        $idKat   = (int)($_POST["id_kategori"] ?? 0);
        $nama    = trim($_POST["nama_merchandise"] ?? "");
        $ukuran  = trim($_POST["ukuran"] ?? "");
        $harga   = $_POST["harga"] ?? "";
        $stok    = $_POST["stok"] ?? "";

        $hargaOk = preg_match("/^\d{1,10}(\.\d{1,2})?$/", (string)$harga) && (float)$harga > 0;
        $stokOk  = preg_match("/^\d{1,7}$/", (string)$stok);

        // Validasi kategori ada
        $kategoriValid = false;
        if ($idKat > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM kategori_merchandise WHERE id_kategori = :id");
            $stmt->execute([":id" => $idKat]);
            $kategoriValid = $stmt->fetchColumn() > 0;
        }

        try {
            if ($nama === "" || $ukuran === "") {
                $error = "Nama dan ukuran wajib diisi.";
            } elseif (!$kategoriValid) {
                $error = "Pilih kategori yang valid.";
            } elseif (!$hargaOk) {
                $error = "Harga harus angka lebih besar dari 0.";
            } elseif (!$stokOk) {
                $error = "Stok harus berupa angka bulat >= 0.";
            } elseif (strlen($ukuran) > 10) {
                $error = "Ukuran maksimal 10 karakter (mis. S, M, L, XL, ALL).";
            } elseif ($action === "create") {

                $upload = proses_upload($_FILES["foto"] ?? []);
                if (!$upload[0]) {
                    $error = $upload[1];
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO merchandise
                            (id_kategori, nama_merchandise, ukuran, harga, stok, foto)
                        VALUES
                            (:id_kategori, :nama, :ukuran, :harga, :stok, :foto)
                    ");
                    $stmt->execute([
                        ":id_kategori" => $idKat,
                        ":nama"        => $nama,
                        ":ukuran"      => $ukuran,
                        ":harga"       => $harga,
                        ":stok"        => $stok,
                        ":foto"        => $upload[1],
                    ]);
                    set_flash("sukses", "Produk \"{$nama}\" ditambahkan.");
                    redirect("merchandise.php");
                }

            } elseif ($action === "update") {

                $stmt = $pdo->prepare("SELECT foto FROM merchandise WHERE id_merchandise = :id");
                $stmt->execute([":id" => $id]);
                $lama = $stmt->fetchColumn();
                if ($lama === false) {
                    $error = "Produk tidak ditemukan.";
                } else {
                    // Foto opsional saat edit — tanpa upload berarti foto lama dipakai
                    $fotoBaru = $lama;
                    if (($_FILES["foto"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $upload = proses_upload($_FILES["foto"]);
                        if (!$upload[0]) {
                            $error = $upload[1];
                        } else {
                            hapus_foto($lama);
                            $fotoBaru = $upload[1];
                        }
                    }

                    if ($error === "") {
                        $stmt = $pdo->prepare("
                            UPDATE merchandise
                            SET id_kategori = :id_kategori, nama_merchandise = :nama,
                                ukuran = :ukuran, harga = :harga, stok = :stok, foto = :foto
                            WHERE id_merchandise = :id
                        ");
                        $stmt->execute([
                            ":id_kategori" => $idKat, ":nama" => $nama,
                            ":ukuran"      => $ukuran, ":harga" => $harga,
                            ":stok"        => $stok,   ":foto"  => $fotoBaru,
                            ":id"          => $id,
                        ]);
                        set_flash("sukses", "Produk \"{$nama}\" diperbarui.");
                        redirect("merchandise.php");
                    }
                }

            } elseif ($action === "delete") {

                $stmt = $pdo->prepare("SELECT foto FROM merchandise WHERE id_merchandise = :id");
                $stmt->execute([":id" => $id]);
                $foto = $stmt->fetchColumn();

                if ($foto !== false) {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM merchandise WHERE id_merchandise = :id");
                        $stmt->execute([":id" => $id]);
                        hapus_foto($foto);
                        set_flash("sukses", "Produk dihapus.");
                        redirect("merchandise.php");
                    } catch (PDOException $e) {
                        // FK RESTRICT: produk sudah masuk detail_pesanan
                        $error = "Produk tidak bisa dihapus karena pernah masuk pesanan.";
                    }
                } else {
                    $error = "Produk tidak ditemukan.";
                }
            }
        } catch (PDOException $e) {
            error_log("CRUD merchandise gagal: " . $e->getMessage());
            $error = "Terjadi kesalahan database. Coba lagi.";
        }
    }
}

// Mode edit: ?edit=ID
if ($error === "" && isset($_GET["edit"])) {
    $stmt = $pdo->prepare("SELECT * FROM merchandise WHERE id_merchandise = :id");
    $stmt->execute([":id" => (int)$_GET["edit"]]);
    $editRow = $stmt->fetch();
}

$kategori = $pdo->query("SELECT id_kategori, nama_kategori FROM kategori_merchandise ORDER BY nama_kategori")->fetchAll();

$produk = $pdo->query("
    SELECT m.*, k.nama_kategori
    FROM merchandise m
    JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
    ORDER BY m.id_merchandise DESC
")->fetchAll();

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Merchandise - Admin</title>
</head>
<body>

    <h1>Kelola Merchandise</h1>
    <p>
        <a href="dashboard.php">&larr; Dashboard</a>
        | <a href="kategori.php">Kelola Kategori</a>
    </p>

    <?php if ($flash): ?>
        <p><b><?= e($flash["msg"]) ?></b></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p style="color:#b00020;"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <h2><?= $editRow ? "Edit Produk" : "Tambah Produk" ?></h2>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editRow ? "update" : "create" ?>">
        <?php if ($editRow): ?>
            <input type="hidden" name="id_merchandise" value="<?= (int)$editRow["id_merchandise"] ?>">
        <?php endif; ?>

        <p>
            <label>Kategori</label><br>
            <select name="id_kategori" required>
                <option value="">-- pilih --</option>
                <?php foreach ($kategori as $k): ?>
                    <option value="<?= (int)$k["id_kategori"] ?>"
                        <?= (isset($editRow["id_kategori"]) && (int)$editRow["id_kategori"] === (int)$k["id_kategori"]) ? "selected" : "" ?>>
                        <?= e($k["nama_kategori"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label>Nama Merchandise</label><br>
            <input type="text" name="nama_merchandise" required maxlength="100"
                   value="<?= e($editRow["nama_merchandise"] ?? "") ?>">
        </p>

        <p>
            <label>Ukuran (S/M/L/XL/ALL)</label><br>
            <input type="text" name="ukuran" required maxlength="10"
                   value="<?= e($editRow["ukuran"] ?? "") ?>">
        </p>

        <p>
            <label>Harga (angka, contoh: 85000)</label><br>
            <input type="text" name="harga" required inputmode="decimal"
                   value="<?= e($editRow["harga"] ?? "") ?>">
        </p>

        <p>
            <label>Stok</label><br>
            <input type="number" name="stok" required min="0"
                   value="<?= e($editRow["stok"] ?? "") ?>">
        </p>

        <p>
            <label>Foto (JPG/PNG/WEBP, maks 2MB<?= $editRow ? " — kosongkan jika tidak diganti" : "" ?>)</label><br>
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" <?= $editRow ? "" : "required" ?>>
            <?php if ($editRow): ?>
                <br><img src="<?= e(UPLOAD_URL . "/" . $editRow["foto"]) ?>" alt="foto saat ini" width="80">
            <?php endif; ?>
        </p>

        <button type="submit"><?= $editRow ? "Simpan Perubahan" : "Tambah Produk" ?></button>
        <?php if ($editRow): ?>
            <a href="merchandise.php">Batal edit</a>
        <?php endif; ?>
    </form>

    <hr>

    <h2>Daftar Produk</h2>

    <table border="1" cellpadding="6" cellspacing="0">
        <tr>
            <th>Foto</th>
            <th>Nama</th>
            <th>Kategori</th>
            <th>Ukuran</th>
            <th>Harga</th>
            <th>Stok</th>
            <th>Aksi</th>
        </tr>
        <?php foreach ($produk as $p): ?>
            <tr>
                <td><img src="<?= e(UPLOAD_URL . "/" . $p["foto"]) ?>" alt="" width="60"></td>
                <td><?= e($p["nama_merchandise"]) ?></td>
                <td><?= e($p["nama_kategori"]) ?></td>
                <td><?= e($p["ukuran"]) ?></td>
                <td>Rp <?= number_format((float)$p["harga"], 0, ",", ".") ?></td>
                <td><?= (int)$p["stok"] ?></td>
                <td>
                    <a href="merchandise.php?edit=<?= (int)$p["id_merchandise"] ?>">Edit</a>
                    |
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Hapus produk ini?')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id_merchandise" value="<?= (int)$p["id_merchandise"] ?>">
                        <button type="submit">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$produk): ?>
            <tr><td colspan="7">Belum ada produk. Tambahkan lewat form di atas.</td></tr>
        <?php endif; ?>
    </table>

</body>
</html>
