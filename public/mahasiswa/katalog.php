<?php

// Katalog mahasiswa: daftar merchandise dengan filter kategori.

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

// Filter kategori (opsional): katalog.php?kategori=ID
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

/** URL foto produk (absolut dari root web); string kosong jika file tidak ada. */
function foto_url(string $foto): string
{
    $nama = basename($foto);
    return is_file(__DIR__ . "/../uploads/merchandise/" . $nama)
        ? "/uploads/merchandise/" . $nama
        : "";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Katalog Merchandise Kampus</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/katalog.css">
    <script src="/assets/js/katalog.js" defer></script>
</head>
<body>
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<main class="catalog">
    <div class="catalog__inner">

        <!-- Hero: judul, pencarian, filter kategori -->
        <header class="catalog__hero">
            <div>
                <h1 class="catalog__title">Produk anjay<?= $namaKategori !== "" ? " — " . e($namaKategori) : "" ?></h1>
                <p class="catalog__subtitle">Koleksi merchandise resmi Jurusan Teknologi Informasi Polinema</p>
            </div>

            <div class="catalog__tools">
                <p class="catalog__links" style="margin:0">
                    <a href="/mahasiswa/dashboard.php">Dashboard</a> |
                    <a href="/logout.php">Logout</a>
                </p>
                <div class="search" role="search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    <input id="cari-produk" type="search" placeholder="Cari produk..." aria-label="Cari produk" autocomplete="off">
                </div>
            </div>
        </header>

        <!-- Filter kategori -->
        <nav class="chips" aria-label="Filter kategori">
            <a class="chip<?= $idKat === 0 ? " is-active" : "" ?>" href="/mahasiswa/katalog.php">Semua</a>
            <?php foreach ($kategori as $k): ?>
                <a class="chip<?= (int)$k["id_kategori"] === $idKat ? " is-active" : "" ?>" href="/mahasiswa/katalog.php?kategori=<?= (int)$k["id_kategori"] ?>"><?= e($k["nama_kategori"]) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$produk): ?>

            <div class="empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5L12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5l9 4.5 9-4.5M12 12v9"/></svg>
                <h2>Belum ada produk</h2>
                <p>Belum ada produk<?= $namaKategori !== "" ? " pada kategori \"" . e($namaKategori) . "\"" : "" ?> untuk saat ini.</p>
                <?php if ($namaKategori !== ""): ?>
                    <a class="pbtn pbtn--cta" href="/mahasiswa/katalog.php"><span>Lihat semua produk</span></a>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="grid">
                <?php foreach ($produk as $p): ?>
                    <?php
                    $foto   = foto_url($p["foto"]);
                    $ada    = (int)$p["stok"] > 0;
                    $ukuran = trim((string)$p["ukuran"]);
                    $detail = "/mahasiswa/produk.php?id=" . (int)$p["id_merchandise"];
                    ?>
                    <article class="pcard<?= $ada ? "" : " pcard--habis" ?>" data-cari="<?= e($p["nama_merchandise"] . " " . $p["nama_kategori"] . " " . $ukuran) ?>">

                        <a class="pcard__photo" href="<?= $detail ?>" aria-label="Lihat detail <?= e($p["nama_merchandise"]) ?>">
                            <?php if ($foto !== ""): ?>
                                <img src="<?= e($foto) ?>" alt="<?= e($p["nama_merchandise"]) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="pcard__nofoto">(tanpa foto)</span>
                            <?php endif; ?>
                            <span class="badge badge--kat"><?= e($p["nama_kategori"]) ?></span>
                            <?php if ($ada): ?>
                                <span class="badge badge--ok">Tersedia (<?= (int)$p["stok"] ?>)</span>
                            <?php else: ?>
                                <span class="badge badge--out">Habis</span>
                            <?php endif; ?>
                        </a>

                        <h2 class="pcard__name" title="<?= e($p["nama_merchandise"]) ?>"><?= e($p["nama_merchandise"]) ?></h2>
                        <p class="pcard__meta"><?= $ukuran !== "" ? "Ukuran " . e($ukuran) : "&nbsp;" ?></p>
                        <p class="pcard__price">Rp. <?= number_format((float)$p["harga"], 0, ",", ".") ?></p>

                        <div class="pcard__actions">
                            <a class="pbtn pbtn--ghost" href="<?= $detail ?>">Detail Produk</a>

                            <?php if ($ada): ?>
                                <!-- Sama seperti form di produk.php: POST ke keranjang.php -->
                                <form method="POST" action="/mahasiswa/keranjang.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="tambah">
                                    <input type="hidden" name="id_merchandise" value="<?= (int)$p["id_merchandise"] ?>">
                                    <input type="hidden" name="jumlah" value="1">
                                    <button class="pbtn pbtn--cta" type="submit">
                                        <img src="/assets/img/icon-cart.png" alt=""><span>+ Keranjang</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="pbtn pbtn--off">Habis</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Tampil oleh katalog.js jika pencarian tidak menemukan apa pun -->
            <div class="empty" id="cari-kosong" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M8.5 11h5"/></svg>
                <h2>Produk tidak ditemukan</h2>
                <p>Coba kata kunci lain atau pilih kategori yang berbeda.</p>
            </div>

        <?php endif; ?>

    </div>
</main>

</body>
</html>
