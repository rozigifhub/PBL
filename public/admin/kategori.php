<?php

/**
 * Admin — CRUD kategori merchandise.
 *
 * Tampilan mengikuti desain UI/UX (navbar admin, kartu form + tabel kategori);
 * auth, CSRF, query, dan logika tambah / ubah / hapus tetap dari tim backend.
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

// ---------- Tampilan (tidak memengaruhi data) ----------

/** Ikon pensil (ikon Edit desain), digambar ulang sebagai SVG: warnanya ikut teks tombol dan bisa dianimasikan lewat CSS. */
function svg_ikon_edit(): string
{
    return '<svg class="kt-pencil" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . '<g transform="rotate(45 12 12)">'
         . '<path d="M9 6.4V17l3 4.2 3-4.2V6.4"/>'
         . '<rect x="8.4" y="1.8" width="7.2" height="4.6" rx="1.2" fill="currentColor" stroke="none"/>'
         . '</g></svg>';
}

/** Ikon tempat sampah (ikon Hapus desain), dibuat dua bagian: tutup (.kt-trash__lid) dan badan (.kt-trash__body) agar tutupnya bisa terbuka saat hover. */
function svg_ikon_hapus(): string
{
    return '<svg class="kt-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . '<g class="kt-trash__lid"><path d="M9.5 2.6h5"/><path d="M3.5 6.2h17"/></g>'
         . '<g class="kt-trash__body"><path d="M6 6.2V19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6.2"/><path d="M10 10.5v6M14 10.5v6"/></g>'
         . '</svg>';
}

$idEdit = $editRow ? (int)$editRow["id_kategori"] : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Kategori - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-kategori.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-kategori.css") ?>">
    <script src="/assets/js/admin-kategori.js?v=<?= @filemtime(__DIR__ . "/../assets/js/admin-kategori.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$adminActive     = "kategori";
$adminNavRingkas = true;   // desain Kelola Kategori: 4 menu + ikon akun saja
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-kategori">
    <div class="kt">

        <!-- Kepala halaman: breadcrumb + ikon + judul -->
        <header class="kt-head">
            <nav class="kt-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/kategori.php" aria-current="page">Kelola Kategori</a></span>
            </nav>
            <div class="kt-title">
                <img class="kt-title__icon" src="/assets/img/icon-folder.png" alt="" width="135" height="135">
                <div>
                    <h1>Kelola Kategori Merchandise</h1>
                    <p>Kelola kategori produk merchandise JTI</p>
                </div>
            </div>
        </header>

        <section class="kt-card" aria-label="Daftar kategori">

            <?php if ($flash): ?>
                <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="<?= ($flash["type"] ?? "") === "sukses" ? "status" : "alert" ?>">
                    <?= e($flash["msg"]) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- Form tambah (create) / ubah (update): field, aksi, dan token CSRF sama seperti dari backend -->
            <form class="kt-form" method="POST" data-mode="<?= $editRow ? "ubah" : "tambah" ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $editRow ? "update" : "create" ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id_kategori" value="<?= $idEdit ?>">
                <?php endif; ?>

                <label class="kt-form__label" for="nama_kategori">
                    Nama Kategori
                    <?php if ($editRow): ?>
                        <span class="kt-form__mode">Mengubah kategori #<?= $idEdit ?></span>
                    <?php endif; ?>
                </label>

                <div class="kt-form__row">
                    <input class="kt-input" id="nama_kategori" type="text" name="nama_kategori" required maxlength="100"
                           placeholder="Masukkan nama kategori..." autocomplete="off"
                           value="<?= e($editRow["nama_kategori"] ?? "") ?>"<?= $editRow ? " autofocus" : "" ?>>

                    <button class="kt-add" type="submit">
                        <?php if ($editRow): ?>
                            Simpan Perubahan
                        <?php else: ?>
                            <span class="kt-add__plus" aria-hidden="true">+</span><span>Tambah Kategori</span>
                        <?php endif; ?>
                    </button>

                    <?php if ($editRow): ?>
                        <a class="kt-cancel" href="/admin/kategori.php">Batal edit</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!$kategori): ?>

                <div class="dash-empty">
                    <p>Belum ada kategori. Tambahkan kategori pertama lewat form di atas.</p>
                </div>

            <?php else: ?>

                <div class="kt-table-wrap">
                    <table class="kt-table">
                        <caption class="kt-sr">Daftar kategori merchandise</caption>
                        <colgroup><col><col><col><col></colgroup>
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Nama Kategori</th>
                                <th scope="col">Jumlah Produk</th>
                                <th scope="col" class="kt-col-aksi">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kategori as $i => $k): ?>
                                <?php
                                $idK     = (int)$k["id_kategori"];
                                $namaK   = (string)$k["nama_kategori"];
                                $konfirm = json_encode("Hapus kategori \"{$namaK}\"?", JSON_UNESCAPED_UNICODE);
                                ?>
                                <tr style="--i:<?= (int)$i ?>"<?= $idK === $idEdit ? ' class="is-editing"' : "" ?>>
                                    <td data-label="ID" class="is-num"><?= $idK ?></td>
                                    <td data-label="Nama Kategori" class="kt-name"><?= e($namaK) ?></td>
                                    <td data-label="Jumlah Produk" class="is-num"><?= (int)$k["jumlah_produk"] ?></td>
                                    <td data-label="Aksi" class="kt-aksi">
                                        <div class="kt-actions">
                                            <a class="kt-btn kt-btn--edit" href="/admin/kategori.php?edit=<?= $idK ?>" aria-label="Edit kategori <?= e($namaK) ?>">
                                                <?= svg_ikon_edit() ?><span>Edit</span>
                                            </a>

                                            <form class="kt-del" method="POST" onsubmit="return confirm(<?= e($konfirm) ?>)">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id_kategori" value="<?= $idK ?>">
                                                <button class="kt-btn kt-btn--hapus" type="submit" aria-label="Hapus kategori <?= e($namaK) ?>">
                                                    <?= svg_ikon_hapus() ?><span>Hapus</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </section>
    </div>
</main>

</body>
</html>
