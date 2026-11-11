<?php

// Beranda (landing page) — tampilan "Beranda Mahasiswa" dari tim desain.
// Logika backend (session, hitung produk/kategori, tautan sesuai role) tetap
// seperti semula; yang baru: Flash Sale (produk acak dari database).

require_once __DIR__ . "/../config/functions.php";
app_session_opsional(); // tamu tanpa cookie: tanpa session (halaman bisa di-cache Nginx)
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/gambar.php";

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

/** Foto kartu Flash Sale: thumbnail bila ada, jika tidak foto asli. */
function foto_kartu_url(string $foto): string
{
    $thumb = thumb_nama($foto);
    return is_file(__DIR__ . "/uploads/merchandise/" . $thumb)
        ? "/uploads/merchandise/" . $thumb
        : foto_url($foto);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchandise Kampus - JTI</title>
    <meta name="description" content="Toko merchandise resmi Jurusan Teknologi Informasi Polinema: kaos, hoodie, tote bag, dan lainnya. Pesan online, bayar DP atau lunas, dan kirim ke alamatmu.">
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <!-- Font judul banner (Poppins Bold, di-host sendiri) + gambar produk banner (LCP) -->
    <link rel="preload" href="/assets/fonts/poppins-bold-latin.woff" as="font" type="font/woff" crossorigin>
    <link rel="preload" as="image" type="image/webp" href="/assets/img/beranda/promo-hoodie.webp" fetchpriority="high">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/beranda.css?v=<?= @filemtime(__DIR__ . "/assets/css/beranda.css") ?>">
    <script src="/assets/js/beranda.js?v=<?= @filemtime(__DIR__ . "/assets/js/beranda.js") ?>" defer></script>
</head>
<body class="page-beranda">
<?php
// Navbar global (sama dengan semua halaman lain): menu lengkap + LOGIN untuk tamu.
$mhsActive = "beranda";
require __DIR__ . "/includes/navbar.php";
?>

<main class="home">
    <div class="home__inner">

        <h1 class="sr-only">Merchandise Kampus</h1>

        <!-- 1. Banner diskon -->
        <!-- Dibangun dari HTML + CSS + gambar produk terpisah (bukan satu gambar utuh) supaya teks, tombol,
             badge, dan bentuk dekoratifnya bisa dianimasikan. Gaya & animasi: assets/css/beranda.css bagian 3. -->
        <section class="promo" aria-label="Promo diskon 20% untuk semua produk JTI Merch">
            <div class="promo__card">

                <!-- Bentuk dekoratif latar: lingkaran sudut yang mengambang pelan -->
                <div class="promo__deco" aria-hidden="true">
                    <span class="promo__dot promo__dot--tl-orange"></span>
                    <span class="promo__dot promo__dot--tl-yellow"></span>
                    <span class="promo__dot promo__dot--tr-yellow"></span>
                    <span class="promo__dot promo__dot--tr-orange"></span>
                    <span class="promo__dot promo__dot--bl-yellow"></span>
                    <span class="promo__dot promo__dot--bl-orange"></span>
                    <span class="promo__dot promo__dot--br-navy-a"></span>
                    <span class="promo__dot promo__dot--br-navy-b"></span>
                </div>

                <div class="promo__grid">

                    <!-- KIRI: teks promo + tombol -->
                    <div class="promo__left">
                        <img class="promo__gift" src="/assets/img/beranda/promo-gift.webp" width="664" height="664" alt="" aria-hidden="true">
                        <p class="promo__pill"><span>Special Hari Ini</span></p>
                        <h2 class="promo__headline">
                            <span class="promo__diskon">Diskon</span>
                            <span class="promo__persen">20%</span>
                        </h2>
                        <p class="promo__sub">Untuk Semua Produk JTI Merch!</p>
                        <p class="promo__desc">Tampil keren dengan merchandise eksklusif JTI.<br>Jangan lewatkan promo Terbatas ini</p>

                        <!-- Tombol menuju katalog: $urlKatalog = /mahasiswa/katalog.php (https://simer.my.id/mahasiswa/katalog.php) -->
                        <a class="promo__cta" href="<?= e($urlKatalog) ?>">
                            <img src="/assets/img/beranda/promo-ic-cart.webp" width="220" height="223" alt="" aria-hidden="true">
                            <span>Pesan Sekarang Juga</span>
                        </a>
                    </div>

                    <!-- KANAN: produk (saling menimpa) + badge diskon -->
                    <div class="promo__stage">
                        <span class="promo__spark promo__spark--1" aria-hidden="true"><img src="/assets/img/beranda/promo-percik.webp" width="275" height="268" alt=""></span>
                        <span class="promo__spark promo__spark--2" aria-hidden="true"><img src="/assets/img/beranda/promo-percik.webp" width="275" height="268" alt=""></span>
                        <span class="promo__spark promo__spark--3" aria-hidden="true"><img src="/assets/img/beranda/promo-percik.webp" width="275" height="268" alt=""></span>

                        <div class="promo__prod promo__prod--baju">
                            <img src="/assets/img/beranda/promo-baju.webp" width="1483" height="1472" alt="Kaos putih berlogo JTI" fetchpriority="high" decoding="async">
                        </div>
                        <div class="promo__prod promo__prod--hoodie">
                            <img src="/assets/img/beranda/promo-hoodie.webp" width="1533" height="1509" alt="Hoodie navy berlogo JTI" fetchpriority="high" decoding="async">
                        </div>
                        <div class="promo__prod promo__prod--tas">
                            <img src="/assets/img/beranda/promo-tas.webp" width="1062" height="1050" alt="Tote bag krem berlogo JTI" decoding="async">
                        </div>

                        <div class="promo__badge" role="img" aria-label="Diskon 20%">
                            <span class="promo__badge-back"></span>
                            <span class="promo__badge-face"></span>
                            <img class="promo__badge-text promo__badge-text--diskon" src="/assets/img/beranda/promo-diskon.webp" width="684" height="254" alt="">
                            <img class="promo__badge-text promo__badge-text--persen" src="/assets/img/beranda/promo-20.webp" width="835" height="424" alt="">
                        </div>
                    </div>

                    <!-- FOOTER banner: 3 keunggulan, dipisah garis vertikal -->
                    <ul class="promo__features" aria-label="Keunggulan produk">
                        <li><img src="/assets/img/beranda/promo-ic-bahan.webp" width="138" height="138" alt="" aria-hidden="true"><span>Bahan Adem<br>Dan Nyaman</span></li>
                        <li><img src="/assets/img/beranda/promo-ic-sablon.webp" width="102" height="102" alt="" aria-hidden="true"><span>Sablon Berkualitas Tahan<br>Lama</span></li>
                        <li><img src="/assets/img/beranda/promo-ic-bintang.webp" width="121" height="117" alt="" aria-hidden="true"><span>Sablon Berkualitas<br>Tahan Lama</span></li>
                    </ul>

                </div>
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
                        $foto   = foto_kartu_url((string)$p["foto"]);
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
                                    <!-- Keranjang hanya untuk mahasiswa yang sudah login; tamu -> login.php?error=cart -->
                                    <a class="fbtn" href="<?= $sudahLogin ? "/login.php" : "/login.php?error=cart" ?>">
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
