<?php

/**
 * Admin — CRUD merchandise (dengan upload foto).
 *
 * Keamanan upload:
 *  - tipe file diverifikasi dari ISI file (finfo), bukan dari nama/ekstensi
 *  - hanya jpeg / png / webp
 *  - nama file diacak (mencegah overwrite & path traversal)
 *  - ukuran maksimal 2 MB
 *
 * Foto otomatis diperkecil + di-encode ulang ke WebP (config/gambar.php):
 * foto detail maks 1000 px dan thumbnail 400 px untuk kartu katalog.
 *
 * Tampilan mengikuti desain UI/UX (navbar admin, kartu form + kartu daftar produk);
 * auth, CSRF, validasi, upload, query, dan logika tambah / ubah / hapus tetap dari tim backend.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/gambar.php";
require_admin();

const UPLOAD_DIR   = __DIR__ . "/../uploads/merchandise";
const UPLOAD_URL   = "/uploads/merchandise";   // absolut dari root web; dulu relatif ("uploads/...") sehingga dari /admin/ foto menunjuk ke /admin/uploads/ dan tidak tampil
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

    // Utama: perkecil + WebP, plus thumbnail untuk katalog
    if (gambar_tersedia()) {
        $namaBaru = bin2hex(random_bytes(8)) . ".webp";

        $ok = gambar_simpan_webp($file["tmp_name"], $mime, [
            UPLOAD_DIR . "/" . $namaBaru             => GAMBAR_SISI_UTAMA,
            UPLOAD_DIR . "/" . thumb_nama($namaBaru) => GAMBAR_SISI_THUMB,
        ]);
        if (!$ok) {
            return [false, "Foto tidak dapat diproses (file rusak atau resolusi terlalu besar, maks ~25 megapiksel)."];
        }

        return [true, $namaBaru];
    }

    // GD/WebP tidak tersedia di server: simpan file asli apa adanya
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
    foreach ([basename($filename), thumb_nama($filename)] as $nama) {
        $path = UPLOAD_DIR . "/" . $nama;
        if (is_file($path)) {
            @unlink($path);
        }
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
        $deskripsi = trim(str_replace("\r\n", "\n", (string)($_POST["deskripsi"] ?? "")));

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
            } elseif (mb_strlen($deskripsi) > 1000) {
                $error = "Deskripsi maksimal 1000 karakter.";
            } elseif ($action === "create") {

                $upload = proses_upload($_FILES["foto"] ?? []);
                if (!$upload[0]) {
                    $error = $upload[1];
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO merchandise
                            (id_kategori, nama_merchandise, deskripsi, ukuran, harga, stok, foto)
                        VALUES
                            (:id_kategori, :nama, :deskripsi, :ukuran, :harga, :stok, :foto)
                    ");
                    $stmt->execute([
                        ":id_kategori" => $idKat,
                        ":nama"        => $nama,
                        ":deskripsi"   => $deskripsi,
                        ":ukuran"      => $ukuran,
                        ":harga"       => $harga,
                        ":stok"        => $stok,
                        ":foto"        => $upload[1],
                    ]);
                    catat_log_admin($pdo, "Tambah", "Produk", (int)$pdo->lastInsertId("merchandise_id_merchandise_seq"),
                        "{$nama} (ukuran {$ukuran}, harga {$harga}, stok {$stok})");
                    set_flash("sukses", "Produk \"{$nama}\" ditambahkan.");
                    redirect("merchandise.php");
                }

            } elseif ($action === "update") {

                $stmt = $pdo->prepare("SELECT * FROM merchandise WHERE id_merchandise = :id");
                $stmt->execute([":id" => $id]);
                $rowLama = $stmt->fetch();
                $lama    = $rowLama === false ? false : $rowLama["foto"];
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
                                deskripsi = :deskripsi,
                                ukuran = :ukuran, harga = :harga, stok = :stok, foto = :foto
                            WHERE id_merchandise = :id
                        ");
                        $stmt->execute([
                            ":id_kategori" => $idKat, ":nama" => $nama,
                            ":deskripsi"   => $deskripsi,
                            ":ukuran"      => $ukuran, ":harga" => $harga,
                            ":stok"        => $stok,   ":foto"  => $fotoBaru,
                            ":id"          => $id,
                        ]);
                        $perubahan = ringkas_perubahan($rowLama, [
                            "id_kategori"      => $idKat,
                            "nama_merchandise" => $nama,
                            "deskripsi"        => $deskripsi,
                            "ukuran"           => $ukuran,
                            "harga"            => number_format((float)$harga, 2, ".", ""),
                            "stok"             => (int)$stok,
                        ]);
                        if ($fotoBaru !== $lama) {
                            $perubahan .= ($perubahan !== "" ? "; " : "") . "foto diganti";
                        }
                        catat_log_admin($pdo, "Ubah", "Produk", $id,
                            $rowLama["nama_merchandise"] . ($perubahan !== "" ? " — " . $perubahan : " — (tanpa perubahan)"));
                        set_flash("sukses", "Produk \"{$nama}\" diperbarui.");
                        redirect("merchandise.php");
                    }
                }

            } elseif ($action === "delete") {

                $stmt = $pdo->prepare("SELECT foto, nama_merchandise FROM merchandise WHERE id_merchandise = :id");
                $stmt->execute([":id" => $id]);
                $rowHapus = $stmt->fetch();
                $foto     = $rowHapus === false ? false : $rowHapus["foto"];

                if ($foto !== false) {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM merchandise WHERE id_merchandise = :id");
                        $stmt->execute([":id" => $id]);
                        hapus_foto($foto);
                        catat_log_admin($pdo, "Hapus", "Produk", $id, $rowHapus["nama_merchandise"]);
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

// ---------- Tampilan (tidak memengaruhi data) ----------

/** URL foto untuk tabel & pratinjau: pakai thumbnail 400 px bila ada (lebih ringan), jika tidak foto aslinya. */
function foto_tabel_url(string $foto): string
{
    $foto  = basename($foto);
    $thumb = thumb_nama($foto);

    return UPLOAD_URL . "/" . (is_file(UPLOAD_DIR . "/" . $thumb) ? $thumb : $foto);
}

/** Kelas warna pil kategori: 6 warna bergilir, tetap sama untuk kategori yang sama (berdasarkan ID). */
function pil_kategori_kelas(int $idKategori): string
{
    return "mp-pill--" . (abs($idKategori) % 6);
}

/** Ikon pensil (ikon Edit desain), digambar ulang sebagai SVG: warnanya ikut teks tombol dan bisa dianimasikan lewat CSS. */
function svg_ikon_edit(): string
{
    return '<svg class="mp-pencil" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . '<g transform="rotate(45 12 12)">'
         . '<path d="M9 6.4V17l3 4.2 3-4.2V6.4"/>'
         . '<rect x="8.4" y="1.8" width="7.2" height="4.6" rx="1.2" fill="currentColor" stroke="none"/>'
         . '</g></svg>';
}

/** Ikon tempat sampah (ikon Hapus desain), dibuat dua bagian: tutup (.mp-trash__lid) dan badan (.mp-trash__body) agar tutupnya bisa terbuka saat hover. */
function svg_ikon_hapus(): string
{
    return '<svg class="mp-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . '<g class="mp-trash__lid"><path d="M9.5 2.6h5"/><path d="M3.5 6.2h17"/></g>'
         . '<g class="mp-trash__body"><path d="M6 6.2V19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6.2"/><path d="M10 10.5v6M14 10.5v6"/></g>'
         . '</svg>';
}

$idEdit       = $editRow ? (int)$editRow["id_merchandise"] : 0;
$fotoSaatIni  = $editRow ? foto_tabel_url((string)$editRow["foto"]) : "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Produk - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-merchandise.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-merchandise.css") ?>">
    <script src="/assets/js/admin-merchandise.js?v=<?= @filemtime(__DIR__ . "/../assets/js/admin-merchandise.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$adminActive     = "produk";
$adminNavRingkas = true;   // desain Kelola Produk: 4 menu + ikon akun saja
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-merch">
    <div class="mp">

        <!-- Kepala halaman: breadcrumb + ikon + judul -->
        <header class="mp-head">
            <nav class="mp-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/merchandise.php" aria-current="page">Kelola Produk</a></span>
            </nav>
            <div class="mp-title">
                <img class="mp-title__icon" src="/assets/img/icon-folder.png" alt="" width="135" height="135">
                <div>
                    <h1>Kelola Produk Merchandise</h1>
                    <p>Kelola produk merchandise JTI</p>
                </div>
            </div>
        </header>

        <!-- ===== Kartu 1: form tambah (create) / ubah (update) ===== -->
        <section class="mp-card mp-card--form" aria-label="Form produk">
            <h2 class="mp-sr"><?= $editRow ? "Edit Produk" : "Tambah Produk" ?></h2>

            <?php if ($flash): ?>
                <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="<?= ($flash["type"] ?? "") === "sukses" ? "status" : "alert" ?>">
                    <?= e($flash["msg"]) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- Field, aksi, dan token CSRF sama seperti dari backend -->
            <form class="mp-form" method="POST" enctype="multipart/form-data" data-mode="<?= $editRow ? "ubah" : "tambah" ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $editRow ? "update" : "create" ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id_merchandise" value="<?= $idEdit ?>">
                    <p class="mp-mode">Mengubah produk #<?= $idEdit ?></p>
                <?php endif; ?>

                <div class="mp-field">
                    <label class="mp-label" for="id_kategori">Kategori</label>
                    <select class="mp-control mp-select" id="id_kategori" name="id_kategori" required>
                        <option value="">-- Pilih --</option>
                        <?php foreach ($kategori as $k): ?>
                            <option value="<?= (int)$k["id_kategori"] ?>"<?= (isset($editRow["id_kategori"]) && (int)$editRow["id_kategori"] === (int)$k["id_kategori"]) ? " selected" : "" ?>><?= e($k["nama_kategori"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mp-field">
                    <label class="mp-label" for="nama_merchandise">Nama Merchandise</label>
                    <input class="mp-control" id="nama_merchandise" type="text" name="nama_merchandise" required maxlength="100" autocomplete="off"
                           value="<?= e($editRow["nama_merchandise"] ?? "") ?>">
                </div>

                <div class="mp-field">
                    <label class="mp-label" for="ukuran">Ukuran (S/M/L/XL/ALL)</label>
                    <input class="mp-control" id="ukuran" type="text" name="ukuran" required maxlength="10" autocomplete="off"
                           placeholder="Contoh: S/M/L/XL/ALL"
                           value="<?= e($editRow["ukuran"] ?? "") ?>">
                </div>

                <div class="mp-field">
                    <label class="mp-label" for="harga">Harga (Contoh: 85000)</label>
                    <input class="mp-control" id="harga" type="text" name="harga" required inputmode="decimal" autocomplete="off"
                           placeholder="Masukkan Harga"
                           value="<?= e($editRow["harga"] ?? "") ?>">
                </div>

                <div class="mp-field">
                    <label class="mp-label" for="stok">Stok</label>
                    <input class="mp-control" id="stok" type="number" name="stok" required min="0"
                           placeholder="Masukkan Stok"
                           value="<?= e($editRow["stok"] ?? "") ?>">
                </div>

                <div class="mp-field">
                    <label class="mp-label" for="foto">
                        Foto (JPG/PNG/WEBP, Max: 2MB)
                        <?php if ($editRow): ?><span class="mp-opt">boleh dikosongkan</span><?php endif; ?>
                    </label>
                    <!-- Input file asli tetap dipakai (transparan, menutupi kotak); tombol, nama file, dan thumbnail hanya tampilan -->
                    <div class="mp-file<?= $editRow ? " has-thumb" : "" ?>">
                        <input class="mp-file__input" id="foto" type="file" name="foto" accept="image/jpeg,image/png,image/webp"<?= $editRow ? "" : " required" ?>>
                        <span class="mp-file__btn" aria-hidden="true">
                            <!-- Ikon upload (Export@3x.png) digambar ulang sebagai SVG supaya panahnya bisa bergerak; centang muncul setelah file dipilih -->
                            <svg class="mp-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <g class="mp-up__arrow"><path d="M12 15.5V4.2"/><path d="m7 8.8 5-5 5 5"/></g>
                                <path d="M4.5 14.5v3a3 3 0 0 0 3 3h9a3 3 0 0 0 3-3v-3"/>
                            </svg>
                            <svg class="mp-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <path pathLength="1" d="m5 12.5 4.5 4.5L19 7.5"/>
                            </svg>
                            <span>Choose File</span>
                        </span>
                        <span class="mp-file__name"><?= $editRow ? "Foto saat ini" : "No File Chosen" ?></span>
                        <img class="mp-file__thumb"<?= $editRow ? ' src="' . e($fotoSaatIni) . '" alt="Foto saat ini"' : ' alt=""' ?> width="32" height="32">
                    </div>
                </div>

                <div class="mp-field mp-field--full">
                    <label class="mp-label" for="deskripsi">Deskripsi Produk <span class="mp-opt">opsional</span></label>
                    <textarea class="mp-control mp-textarea" id="deskripsi" name="deskripsi" rows="3" maxlength="1000"
                              placeholder="Masukkan Deskripsi (Maksimal 1000 Karakter)"><?= e($editRow["deskripsi"] ?? ($_POST["deskripsi"] ?? "")) ?></textarea>
                </div>

                <div class="mp-form__foot">
                    <button class="mp-add" type="submit">
                        <?php if ($editRow): ?>
                            Simpan Perubahan
                        <?php else: ?>
                            <span class="mp-add__plus" aria-hidden="true">+</span><span>Tambah Produk</span>
                        <?php endif; ?>
                    </button>

                    <?php if ($editRow): ?>
                        <a class="mp-cancel" href="/admin/merchandise.php">Batal edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- ===== Kartu 2: daftar produk ===== -->
        <section class="mp-card mp-card--list" aria-label="Daftar produk">

            <div class="mp-list-head">
                <span class="mp-list-icon" aria-hidden="true">
                    <!-- Ikon topi wisuda (desain), garis putih di atas lingkaran krem -->
                    <svg viewBox="0 0 48 48" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 18 24 8l21 10-21 10z"/>
                        <path d="M12 23.5V32c0 3.3 5.4 6 12 6s12-2.7 12-6v-8.5"/>
                        <path d="M45 18v11"/>
                    </svg>
                </span>
                <div>
                    <h2>Daftar Produk</h2>
                    <p>List produk yang tersedia di toko</p>
                </div>
            </div>

            <?php if (!$produk): ?>

                <div class="dash-empty">
                    <p>Belum ada produk. Tambahkan lewat form di atas.</p>
                </div>

            <?php else: ?>

                <div class="mp-table-wrap">
                    <table class="mp-table">
                        <caption class="mp-sr">Daftar produk merchandise</caption>
                        <colgroup><col><col><col><col><col><col><col></colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Foto</th>
                                <th scope="col" class="mp-col-nama">Nama Produk</th>
                                <th scope="col">Kategori</th>
                                <th scope="col">Harga</th>
                                <th scope="col">Stock</th>
                                <th scope="col">Ukuran</th>
                                <th scope="col" class="mp-col-aksi">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produk as $i => $p): ?>
                                <?php
                                $idP     = (int)$p["id_merchandise"];
                                $namaP   = (string)$p["nama_merchandise"];
                                $konfirm = json_encode("Hapus produk \"{$namaP}\"?", JSON_UNESCAPED_UNICODE);
                                ?>
                                <tr style="--i:<?= (int)$i ?>"<?= $idP === $idEdit ? ' class="is-editing"' : "" ?>>
                                    <td data-label="Foto">
                                        <span class="mp-thumb"><img src="<?= e(foto_tabel_url((string)$p["foto"])) ?>" alt="Foto <?= e($namaP) ?>" width="62" height="71" loading="lazy" decoding="async"></span>
                                    </td>
                                    <td data-label="Nama Produk" class="mp-name"><?= e($namaP) ?></td>
                                    <td data-label="Kategori"><span class="mp-pill <?= e(pil_kategori_kelas((int)$p["id_kategori"])) ?>"><?= e($p["nama_kategori"]) ?></span></td>
                                    <td data-label="Harga" class="is-num">Rp <?= number_format((float)$p["harga"], 0, ",", ".") ?></td>
                                    <td data-label="Stock" class="is-num"><?= (int)$p["stok"] ?></td>
                                    <td data-label="Ukuran"><?= e($p["ukuran"]) ?></td>
                                    <td data-label="Aksi" class="mp-aksi">
                                        <div class="mp-actions">
                                            <a class="mp-btn mp-btn--edit" href="/admin/merchandise.php?edit=<?= $idP ?>" aria-label="Edit produk <?= e($namaP) ?>">
                                                <?= svg_ikon_edit() ?><span>Edit</span>
                                            </a>

                                            <form class="mp-del" method="POST" onsubmit="return confirm(<?= e($konfirm) ?>)">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id_merchandise" value="<?= $idP ?>">
                                                <button class="mp-btn mp-btn--hapus" type="submit" aria-label="Hapus produk <?= e($namaP) ?>">
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
