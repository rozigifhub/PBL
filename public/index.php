<?php

// Beranda (landing page) — tampilan "Beranda Mahasiswa" dari tim desain.
// Logika backend (session, hitung produk/kategori, tautan sesuai role) tetap
// seperti semula; yang baru: Flash Sale (produk acak dari database).

require_once __DIR__ . "/../config/functions.php";
app_session_start();
require_once __DIR__ . "/../config/database.php";

// Tujuan tombol "Beli Sekarang" di banner dan tautan "Lihat Semua".
// Ubah di sini jika alamat katalog berubah.
$urlKatalog = "/mahasiswa/katalog.php";

$jumlahProduk   = (int)$pdo->query("SELECT COUNT(*) FROM merchandise")->fetchColumn();
$jumlahKategori = (int)$pdo->query("SELECT COUNT(*) FROM kategori_merchandise")->fetchColumn();

// Flash Sale: produk ACAK dari database (hanya yang stoknya masih ada).
// Urutannya berganti setiap halaman dimuat ulang. Ubah angka LIMIT untuk
// menambah / mengurangi jumlah kartu (desain memakai 3 kartu per baris).
$flashSale = $pdo->query("
    SELECT m.id_merchandise, m.nama_merchandise, m.ukuran, m.harga, m.stok, m.foto,
           k.nama_kategori
    FROM merchandise m
    JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
    WHERE m.stok > 0
    ORDER BY RANDOM()
    LIMIT 3
")->fetchAll();

$sudahLogin  = !empty($_SESSION["id_akun"]);
$role        = $_SESSION["role"] ?? "";
$isMahasiswa = $sudahLogin && $role === "Mahasiswa";

/** URL foto produk (absolut dari root web); string kosong jika file tidak ada. */
if (!function_exists("foto_url")) {
    function foto_url(string $foto): string
    {
        $nama = basename($foto);
        return is_file(__DIR__ . "/uploads/merchandise/" . $nama)
            ? "/uploads/merchandise/" . $nama
            : "";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchandise Kampus - JTI</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/beranda.css?v=<?= @filemtime(__DIR__ . "/assets/css/beranda.css") ?>">
    <script src="/assets/js/beranda.js?v=<?= @filemtime(__DIR__ . "/assets/js/beranda.js") ?>" defer></script>
</head>
<body class="page-beranda">
<?php require __DIR__ . "/includes/navbar.php"; ?>

<main class="home">
    <div class="home__inner">

        <h1 class="sr-only">Merchandise Kampus</h1>

        <!-- 1. Banner diskon -->
        <section class="promo" aria-label="Promo diskon 20% untuk semua produk JTI Merch">
            <div class="promo__frame">
                <picture>
                    <source srcset="/assets/img/beranda/banner-diskon.webp" type="image/webp">
                    <img class="promo__img" src="/assets/img/beranda/banner-diskon.png" width="1672" height="693"
                         alt="Diskon 20% untuk semua produk JTI Merch. Tampil keren dengan merchandise eksklusif JTI. Jangan lewatkan promo terbatas ini!">
                </picture>

                <!-- Ikon megaphone (terpisah dari banner supaya bisa bergoyang) -->
                <img class="promo__mega" src="/assets/img/beranda/megafon.png" width="121" height="96" alt="" aria-hidden="true">

                <!-- Area klik transparan tepat di atas tombol "BELI SEKARANG" pada gambar banner -->
                <a class="promo__cta" href="<?= e($urlKatalog) ?>" aria-label="Beli sekarang — lihat katalog produk"></a>
            </div>
        </section>

        <!-- 2. Tagline (teks dari backend) -->
        <p class="hm-card hm-tagline">
            <span class="hm-bubble" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2.4 6.1 6.1 2.4-6.1 2.4L12 19.5l-2.4-6.1-6.1-2.4 6.1-2.4L12 2.5zM19 15l.9 2.1 2.1.9-2.1.9L19 21l-.9-2.1-2.1-.9 2.1-.9L19 15z"/></svg>
            </span>
            <span><strong>Technology for a Brighter Future</strong> — merchandise resmi untuk mahasiswa.</span>
        </p>

        <!-- 3. Flash Sale -->
        <section class="flash" aria-labelledby="judul-flash">
            <div class="flash__head">
                <img class="flash__bolt" src="/assets/img/beranda/logo-petir.png" width="106" height="106" alt="">
                <div class="flash__titles">
                    <h2 class="flash__title" id="judul-flash">Flash Sale</h2>
                    <p class="flash__sub">Harga Special Untuk Waktu Terbatas!</p>
                </div>
                <a class="flash__all" href="<?= e($urlKatalog) ?>">
                    <span>Lihat Semua</span>
                    <svg viewBox="0 0 48 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12h43M35 3l10 9-10 9"/></svg>
                </a>
            </div>

            <?php if (!$flashSale): ?>

                <div class="flash__empty">
                    <p>Belum ada produk Flash Sale untuk saat ini.</p>
                </div>

            <?php else: ?>

                <div class="flash__grid<?= count($flashSale) < 3 ? " flash__grid--few" : "" ?>">
                    <?php foreach ($flashSale as $i => $p): ?>
                        <?php
                        $foto   = foto_url((string)$p["foto"]);
                        $detail = "/mahasiswa/produk.php?id=" . (int)$p["id_merchandise"];
                        $ukuran = trim((string)$p["ukuran"]);
                        $meta   = $p["nama_kategori"] . ($ukuran !== "" ? " • Ukuran " . $ukuran : "");
                        ?>
                        <article class="fcard" style="--i:<?= (int)$i ?>">

                            <a class="fcard__photo" href="<?= e($detail) ?>" aria-label="Lihat detail <?= e($p["nama_merchandise"]) ?>">
                                <?php if ($foto !== ""): ?>
                                    <img src="<?= e($foto) ?>" alt="<?= e($p["nama_merchandise"]) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="fcard__nofoto">(tanpa foto)</span>
                                <?php endif; ?>
                            </a>

                            <h3 class="fcard__name" title="<?= e($p["nama_merchandise"]) ?>"><?= e($p["nama_merchandise"]) ?></h3>
                            <p class="fcard__meta"><?= e($meta) ?></p>
                            <p class="fcard__price">Rp. <?= number_format((float)$p["harga"], 0, ",", ".") ?></p>

                            <div class="fcard__actions">
                                <?php if ($isMahasiswa): ?>
                                    <!-- Sama seperti form di katalog.php / produk.php: POST ke keranjang.php -->
                                    <form method="POST" action="/mahasiswa/keranjang.php">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="tambah">
                                        <input type="hidden" name="id_merchandise" value="<?= (int)$p["id_merchandise"] ?>">
                                        <input type="hidden" name="jumlah" value="1">
                                        <button class="fbtn" type="submit">
                                            <img src="/assets/img/icon-cart.png" alt=""><span>Tambah ke Keranjang</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!-- Keranjang hanya untuk mahasiswa yang sudah login -->
                                    <a class="fbtn" href="/login.php">
                                        <img src="/assets/img/icon-cart.png" alt=""><span>Tambah ke Keranjang</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </section>

        <!-- 4. Ringkasan + tautan (teks dari backend) -->
        <section class="hm-card hm-summary" aria-label="Ringkasan katalog">
            <span class="hm-bubble" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5L12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5l9 4.5 9-4.5M12 12v9"/></svg>
            </span>

            <p class="hm-summary__stat">
                Tersedia <b><?= $jumlahProduk ?></b> produk
                dalam <b><?= $jumlahKategori ?></b> kategori.
            </p>

            <?php if (!$sudahLogin): ?>

                <p class="hm-summary__note">Login untuk melihat katalog dan berbelanja:</p>
                <p class="hm-summary__links">
                    <a class="hm-summary__main" href="/login.php">Login</a>
                    <span class="hm-summary__sep" aria-hidden="true"></span>
                    <a class="hm-summary__alt" href="/register.php">Register</a>
                </p>

            <?php elseif ($isMahasiswa): ?>

                <p class="hm-summary__links">
                    <a class="hm-summary__main" href="<?= e($urlKatalog) ?>">Lihat Katalog <span class="hm-summary__arrow" aria-hidden="true">&rarr;</span></a>
                    <span class="hm-summary__sep" aria-hidden="true"></span>
                    <a class="hm-summary__alt" href="/logout.php">Logout</a>
                </p>

            <?php else: ?>

                <p class="hm-summary__links">
                    <a class="hm-summary__main" href="/admin/dashboard.php">Dashboard Admin</a>
                    <span class="hm-summary__sep" aria-hidden="true"></span>
                    <a class="hm-summary__alt" href="/logout.php">Logout</a>
                </p>

            <?php endif; ?>
        </section>

    </div>
</main>

</body>
</html>
