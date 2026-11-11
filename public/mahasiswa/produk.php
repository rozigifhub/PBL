<?php

// Detail produk: produk.php?id=ID
//
// Tampilan mengikuti desain Figma "Detail Produk" (CSS: assets/css/produk-detail.css,
// JS: assets/js/produk-detail.js).
//
// YANG TIDAK DIUBAH : session, require_mahasiswa(), query utama $produk, $fotoUrl, $sudahLogin,
//                     $loginNext, dan form POST ke /mahasiswa/keranjang.php (csrf_token, action=tambah,
//                     id_merchandise, jumlah).
// YANG DITAMBAH     : (1) $lainnya  -> 3 produk ACAK untuk seksi "Pilih Lainnya"
//                     (2) variabel kecil khusus tampilan: $ada, $stokLabel, $daftarUkuran, $ukuranDipilih

require_once __DIR__ . "/../../config/functions.php";
app_session_opsional(); // tamu tanpa cookie: tanpa session (halaman bisa di-cache)
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/gambar.php"; // thumb_nama(): thumbnail untuk kartu "Pilih Lainnya"
// Boleh dilihat tamu (belum login); akun non-mahasiswa tetap ditolak.
$sudahLogin = !empty($_SESSION["id_akun"]);
if ($sudahLogin) {
    require_mahasiswa();
}
$loginNext = "/login.php?next=" . rawurlencode($_SERVER["REQUEST_URI"]);

$id = (int)($_GET["id"] ?? 0);

$stmt = $pdo->prepare("
    SELECT m.id_merchandise, m.nama_merchandise, m.deskripsi, m.ukuran, m.harga, m.stok, m.foto,
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
    $fotoUrl  = is_file(__DIR__ . "/../uploads/merchandise/" . $namaFoto)
        ? "/uploads/merchandise/" . $namaFoto
        : "";
}

// ---------------------------------------------------------------------------
// TAMBAHAN (khusus tampilan)
// ---------------------------------------------------------------------------

/** URL foto produk (absolut dari root web); string kosong jika file tidak ada. */
if (!function_exists("foto_url")) {
    function foto_url(string $foto): string
    {
        $nama = basename($foto);
        return is_file(__DIR__ . "/../uploads/merchandise/" . $nama)
            ? "/uploads/merchandise/" . $nama
            : "";
    }
}

/** Foto kartu: thumbnail bila ada, jika tidak foto asli (sama seperti katalog & beranda). */
if (!function_exists("foto_kartu_url")) {
    function foto_kartu_url(string $foto): string
    {
        $thumb = thumb_nama($foto);
        return is_file(__DIR__ . "/../uploads/merchandise/" . $thumb)
            ? "/uploads/merchandise/" . $thumb
            : foto_url($foto);
    }
}

$ada            = false;  // stok > 0 ?
$stokLabel      = "0";    // teks jumlah stok di Card Stok ("1000+" bila stok >= 1000)
$daftarUkuran   = [];     // isi tombol pilihan ukuran
$ukuranDipilih  = "";     // ukuran yang terpilih saat halaman dibuka
$lainnya        = [];     // produk acak untuk "Pilih Lainnya"
$kelasNama      = "";     // kelas tambahan judul: nama panjang -> huruf mengecil (pd-name--sm / pd-name--xs)

if ($produk !== false) {
    $ada       = (int)$produk["stok"] > 0;
    $stokLabel = (int)$produk["stok"] >= 1000 ? "1000+" : (string)(int)$produk["stok"];

    $panjangNama = strlen((string)$produk["nama_merchandise"]);
    $kelasNama   = $panjangNama > 36 ? " pd-name--xs" : ($panjangNama > 20 ? " pd-name--sm" : "");

    // Kolom `ukuran` berisi SATU teks (maks 10 karakter), mis. "ALL", "M", atau "S/M/L".
    // "ALL" = semua ukuran standar (sesuai label di admin: S/M/L/XL/ALL) -> tampil S, M, L, XL.
    $ukuranMentah = strtoupper(trim((string)$produk["ukuran"]));
    if ($ukuranMentah === "" || $ukuranMentah === "ALL") {
        $daftarUkuran = ["S", "M", "L", "XL"];
    } else {
        $daftarUkuran = array_values(array_filter(
            preg_split('/\s*[,\/;|]\s*/', $ukuranMentah),
            static fn($u) => $u !== ""
        ));
    }
    // Ukuran awal terpilih: "M" bila ada (seperti desain), jika tidak ukuran pertama.
    $ukuranDipilih = in_array("M", $daftarUkuran, true) ? "M" : ($daftarUkuran[0] ?? "");

    // "Pilih Lainnya": 3 produk ACAK (bukan produk yang sedang dibuka, hanya yang stoknya ada).
    // Database proyek ini PostgreSQL (lihat config/database.php, DSN "pgsql:"), jadi fungsi acaknya
    // RANDOM(), BUKAN RAND() (RAND() hanya ada di MySQL dan akan error di Postgres).
    // Sama seperti Flash Sale di index.php. Ubah angka LIMIT untuk menambah/mengurangi jumlah kartu.
    try {
        $stmtLain = $pdo->prepare("
            SELECT m.id_merchandise, m.nama_merchandise, m.harga, m.stok, m.foto,
                   k.nama_kategori
            FROM merchandise m
            JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
            WHERE m.id_merchandise <> :id AND m.stok > 0
            ORDER BY RANDOM()
            LIMIT 3
        ");
        $stmtLain->execute([":id" => (int)$produk["id_merchandise"]]);
        $lainnya = $stmtLain->fetchAll();
    } catch (PDOException $ex) {
        // Seksi pelengkap: kalau gagal, halaman detail tetap tampil tanpa "Pilih Lainnya".
        error_log("produk.php: gagal membaca produk acak: " . $ex->getMessage());
        $lainnya = [];
    }
}

// Atribut penghubung kontrol (ukuran, jumlah, tombol) ke <form id="form-keranjang">.
// Hanya diisi untuk mahasiswa yang sudah login dan produk yang stoknya ada.
$formAttr = ($produk !== false && $ada && $sudahLogin) ? ' form="form-keranjang"' : "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $produk !== false ? e($produk["nama_merchandise"]) . " - Merchandise Kampus" : "Produk tidak ditemukan" ?></title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/produk-detail.css?v=<?= @filemtime(__DIR__ . "/../assets/css/produk-detail.css") ?>">
    <link rel="stylesheet" href="/assets/css/menu-cepat.css?v=<?= @filemtime(__DIR__ . "/../assets/css/menu-cepat.css") ?>">
    <script src="/assets/js/produk-detail.js?v=<?= @filemtime(__DIR__ . "/../assets/js/produk-detail.js") ?>" defer></script>
</head>
<body>
<?php
$mhsActive = "produk";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>

<main class="pd">
    <div class="pd__inner">

    <?php if ($produk === false): ?>

        <!-- ===== PRODUK TIDAK DITEMUKAN (logika asli dipertahankan) ===== -->
        <section class="pd-empty">
            <h1 class="pd-empty__title">Produk tidak ditemukan</h1>
            <p class="pd-empty__text">Produk mungkin sudah dihapus atau tautannya salah.</p>
            <a class="pd-btn pd-btn--cta pd-btn--inline" href="/mahasiswa/katalog.php">&larr; Kembali ke Katalog</a>
        </section>

    <?php else: ?>

        <p class="pd-back"><a href="/mahasiswa/katalog.php">&larr; Kembali ke Katalog</a></p>

        <!-- ===== 1. AREA ATAS: 2 kolom (kiri gambar, kanan info) ===== -->
        <section class="pd-top">

            <!-- ---------- KOLOM KIRI: 1 gambar utama (thumbnail dihapus, backend hanya punya 1 foto) ----------
                 Mode isi gambar diatur di CSS: --pd-fit: cover (penuh, sedikit terpotong) atau contain (utuh). -->
            <div class="pd-gallery">
                <div class="pd-photo">
                    <?php if ($fotoUrl !== ""): ?>
                        <!-- PHP: $fotoUrl = foto produk, $produk["nama_merchandise"] = teks alt -->
                        <img src="<?= e($fotoUrl) ?>" alt="<?= e($produk["nama_merchandise"]) ?>" width="1000" height="1000" fetchpriority="high">
                    <?php else: ?>
                        <div class="pd-photo__none">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5L12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5l9 4.5 9-4.5M12 12v9"/></svg>
                            <i>(tanpa foto)</i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ---------- KOLOM KANAN: info produk ---------- -->
            <div class="pd-info">

                <!-- PHP: $produk["nama_merchandise"] -->
                <h1 class="pd-name<?= $kelasNama ?>"><?= e($produk["nama_merchandise"]) ?></h1>

                <!-- PHP: $produk["harga"] (format Rupiah, sama seperti kode asli) -->
                <p class="pd-price">Rp <?= number_format((float)$produk["harga"], 0, ",", ".") ?></p>

                <!-- Card Stok: abu-abu terang. Badge hijau "TERSEDIA" / merah "HABIS" + jumlah stok -->
                <section class="pd-stock" aria-label="Stok produk">
                    <h2 class="pd-stock__title">
                        <img src="/assets/img/produk-package.png" alt="">
                        <span>STOCK PRODUK</span>
                    </h2>
                    <div class="pd-stock__row">
                        <?php if ($ada): ?>
                            <span class="pd-badge pd-badge--ok">
                                <img src="/assets/img/produk-check.png" alt="">
                                <span>TERSEDIA</span>
                            </span>
                        <?php else: ?>
                            <span class="pd-badge pd-badge--off">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                <span>HABIS</span>
                            </span>
                        <?php endif; ?>

                        <span class="pd-stock__divider" aria-hidden="true"></span>

                        <!-- PHP: $stokLabel (dari $produk["stok"]; tampil "1000+" bila stok >= 1000) -->
                        <span class="pd-count" aria-label="Jumlah stok <?= (int)$produk["stok"] ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M3 12.2V4.5A1.5 1.5 0 0 1 4.5 3h7.7a1.5 1.5 0 0 1 1.06.44l7.3 7.3a1.5 1.5 0 0 1 0 2.12l-7.7 7.7a1.5 1.5 0 0 1-2.12 0l-7.3-7.3A1.5 1.5 0 0 1 3 12.2z"/><circle cx="8" cy="8" r="1.3"/></svg>
                            <b><?= e($stokLabel) ?></b>
                        </span>
                    </div>
                </section>

                <hr class="pd-sep">

                <!-- Baris Kategori (di desain: "Material"; database belum punya kolom material, jadi
                     slot ini memakai kategori + tautan filter katalog seperti di halaman lama) -->
                <div class="pd-row">
                    <span class="pd-row__icon"><img src="/assets/img/produk-material.png" alt=""></span>
                    <div class="pd-row__body">
                        <p class="pd-row__label">Kategori</p>
                        <!-- PHP: $produk["id_kategori"] (tautan) dan $produk["nama_kategori"] (teks) -->
                        <a class="pd-row__sub" href="/mahasiswa/katalog.php?kategori=<?= (int)$produk["id_kategori"] ?>"><?= e($produk["nama_kategori"]) ?></a>
                    </div>
                </div>

                <?php if ($ada): ?>

                    <?php if ($sudahLogin): ?>
                        <!-- FORM KERANJANG: persis seperti kode asli (action, method, field tidak diubah).
                             Ukuran, jumlah, dan tombol di bawah terhubung ke form ini lewat atribut form="form-keranjang". -->
                        <form id="form-keranjang" method="POST" action="/mahasiswa/keranjang.php" data-form-keranjang>
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="tambah">
                            <input type="hidden" name="id_merchandise" value="<?= (int)$produk["id_merchandise"] ?>">
                        </form>
                    <?php endif; ?>

                    <!-- Pilih Ukuran (radio bergaya kotak; bisa dipakai tanpa JS).
                         CATATAN: keranjang.php saat ini hanya menyimpan id + jumlah, jadi field "ukuran" ikut terkirim
                         tapi diabaikan backend. Siap dipakai bila tim backend nanti menyimpan ukuran per item. -->
                    <div class="pd-row pd-row--ukuran">
                        <span class="pd-row__icon"><img src="/assets/img/produk-shirt.png" alt=""></span>
                        <div class="pd-row__body">
                            <p class="pd-row__label" id="lbl-ukuran">Pilih Ukuran</p>
                            <div class="pd-sizes" role="radiogroup" aria-labelledby="lbl-ukuran">
                                <?php foreach ($daftarUkuran as $u): ?>
                                    <label class="pd-size">
                                        <input type="radio" name="ukuran" value="<?= e($u) ?>"<?= $u === $ukuranDipilih ? " checked" : "" ?><?= $formAttr ?>>
                                        <span><?= e($u) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Jumlah: ikon keranjang + label dalam SATU baris, kotak [ - ][ 1 ][ + ] di bawahnya (diatur produk-detail.js).
                         "(maks N)" = batas jumlah yang bisa dipesan = stok produk. -->
                    <div class="pd-qtyrow">
                        <label class="pd-qtyrow__label" for="jumlah">
                            <img class="pd-qtyrow__icon" src="/assets/img/produk-basket.png" alt="">
                            <span>Jumlah</span>
                            <!-- PHP: $produk["stok"] -->
                            <span class="pd-qtyrow__max">(maks <?= (int)$produk["stok"] ?>)</span>
                        </label>
                        <div class="pd-qty" data-qty>
                            <button class="pd-qty__btn" type="button" data-qty-minus aria-label="Kurangi jumlah">-</button>
                            <!-- PHP: max = $produk["stok"]. name="jumlah" sama seperti kode asli -->
                            <input class="pd-qty__input" id="jumlah" type="number" name="jumlah" value="1" min="1" max="<?= (int)$produk["stok"] ?>" required inputmode="numeric"<?= $formAttr ?>>
                            <button class="pd-qty__btn" type="button" data-qty-plus aria-label="Tambah jumlah">+</button>
                        </div>
                    </div>

                    <div class="pd-actions">
                        <?php if (!$sudahLogin): ?>
                            <!-- Tamu: arahkan ke login (logika asli: "Login untuk menambahkan ke keranjang") -->
                            <a class="pd-btn pd-btn--cta" href="<?= e($loginNext) ?>">
                                <img class="pd-btn__icon" src="/assets/img/produk-basket.png" alt="">
                                <span>TAMBAH KE KERANJANG</span>
                            </a>
                            <a class="pd-btn pd-btn--outline" href="<?= e($loginNext) ?>"><span>BELI SEKARANG</span></a>
                            <p class="pd-actions__note">Login untuk menambahkan ke keranjang.</p>
                        <?php else: ?>
                            <!-- Tombol oranye: hover = naik sedikit + ikon keranjang "melaju" (lihat CSS .pd-btn--cta) -->
                            <button class="pd-btn pd-btn--cta" type="submit" form="form-keranjang">
                                <img class="pd-btn__icon" src="/assets/img/produk-basket.png" alt="">
                                <span>TAMBAH KE KERANJANG</span>
                            </button>
                            <!-- Beli Sekarang: backend belum punya alur "beli langsung", jadi memakai form yang sama
                                 (action=tambah) dan berakhir di halaman keranjang. name="beli_sekarang" diabaikan backend;
                                 siap dipakai bila nanti ingin langsung diarahkan ke checkout. -->
                            <button class="pd-btn pd-btn--outline" type="submit" form="form-keranjang" name="beli_sekarang" value="1">
                                <span>BELI SEKARANG</span>
                            </button>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <!-- Stok habis: tidak ada form (sama seperti kode asli) -->
                    <div class="pd-actions">
                        <span class="pd-btn pd-btn--off" aria-disabled="true"><span>STOK HABIS</span></span>
                    </div>

                <?php endif; ?>

            </div>
        </section>

        <!-- ===== 2. DESKRIPSI PRODUK: hanya teks dari backend (3 kotak fitur dihapus) ===== -->
        <section class="pd-desc">
            <h2 class="pd-desc__title">DESKRIPSI PRODUK</h2>
            <?php if (trim((string)$produk["deskripsi"]) !== ""): ?>
                <!-- PHP: $produk["deskripsi"]. Ditulis satu baris (tanpa spasi di sekitar tag PHP) karena CSS memakai
                     white-space: pre-line agar baris baru dari admin tetap tampil. -->
                <p class="pd-desc__text"><?= e($produk["deskripsi"]) ?></p>
            <?php else: ?>
                <p class="pd-desc__text pd-desc__text--kosong">Belum ada deskripsi untuk produk ini.</p>
            <?php endif; ?>
        </section>

        <!-- ===== 3. PILIH LAINNYA: 3 produk acak dari database ($lainnya, ORDER BY RANDOM() LIMIT 3) ===== -->
        <?php if ($lainnya): ?>
        <section class="pd-more" aria-labelledby="judul-lainnya">
            <div class="pd-more__head">
                <h2 class="pd-more__title" id="judul-lainnya">Pilih Lainnya</h2>
                <a class="pd-more__all" href="/mahasiswa/katalog.php">
                    <span>Lihat Semua</span>
                    <svg viewBox="0 0 24 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 6h21M17 1l5 5-5 5"/></svg>
                </a>
            </div>

            <div class="pd-more__grid">
                <?php foreach ($lainnya as $p): ?>
                    <?php
                    // Variabel per kartu (dari loop $lainnya)
                    $fotoKartu = foto_kartu_url($p["foto"]);
                    $detail    = "/mahasiswa/produk.php?id=" . (int)$p["id_merchandise"];
                    ?>
                    <article class="pd-card">
                        <a class="pd-card__photo" href="<?= $detail ?>" aria-label="Lihat detail <?= e($p["nama_merchandise"]) ?>">
                            <?php if ($fotoKartu !== ""): ?>
                                <!-- PHP: $fotoKartu, $p["nama_merchandise"] -->
                                <img src="<?= e($fotoKartu) ?>" alt="<?= e($p["nama_merchandise"]) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="pd-card__nofoto">(tanpa foto)</span>
                            <?php endif; ?>
                        </a>

                        <h3 class="pd-card__name" title="<?= e($p["nama_merchandise"]) ?>"><a href="<?= $detail ?>"><?= e($p["nama_merchandise"]) ?></a></h3>
                        <!-- PHP: $p["nama_kategori"] -->
                        <p class="pd-card__kat"><?= e($p["nama_kategori"]) ?></p>
                        <!-- PHP: $p["harga"] -->
                        <p class="pd-card__price">Rp. <?= number_format((float)$p["harga"], 0, ",", ".") ?></p>

                        <div class="pd-card__actions">
                            <?php if (!$sudahLogin): ?>
                                <!-- Tamu: arahkan ke login, lalu kembali ke halaman ini -->
                                <a class="pd-card__btn" href="<?= e($loginNext) ?>">
                                    <img src="/assets/img/icon-cart.png" alt=""><span>Tambah ke Keranjang</span>
                                </a>
                            <?php else: ?>
                                <!-- Sama seperti form di katalog.php: POST ke keranjang.php, jumlah 1 -->
                                <form method="POST" action="/mahasiswa/keranjang.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="tambah">
                                    <input type="hidden" name="id_merchandise" value="<?= (int)$p["id_merchandise"] ?>">
                                    <input type="hidden" name="jumlah" value="1">
                                    <button class="pd-card__btn" type="submit">
                                        <img src="/assets/img/icon-cart.png" alt=""><span>Tambah ke Keranjang</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    <?php endif; ?>

    </div>

    <?php if ($sudahLogin): ?>
        <!-- Menu cepat (paling bawah): sama dengan dashboard. Hanya tampil bila sudah login,
             karena tamu belum punya dashboard/profil/riwayat. -->
        <nav class="menu-cepat" aria-label="Menu cepat">
            <a class="menu-cepat__main" href="/mahasiswa/dashboard.php">Lihat Dashboard</a>
            <a href="/mahasiswa/riwayat.php">Riwayat Pesanan</a>
            <a href="/mahasiswa/profil.php">Kelola Profil</a>
            <a class="menu-cepat__out" href="/logout.php">Logout</a>
        </nav>
    <?php endif; ?>
</main>

</body>
</html>
