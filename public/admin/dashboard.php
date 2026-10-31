<?php

// Dashboard admin: 4 kartu ringkasan + List Merchandise + Produk Terlaris.
// Tampilan mengikuti desain UI/UX; auth dan sumber data (materialized view
// mv_rekap_penjualan_produk) tetap dari tim backend.

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

// ---------- Pengaturan (ubah di sini) ----------

// Tujuan semua tombol "Kelola" (di kedua tabel).
$urlKelola = "https://simer.my.id/admin/merchandise.php";

// Jumlah baris per tabel; selebihnya bisa dilihat lewat halaman Kelola.
$batasProduk   = 5;   // List Merchandise: produk terbaru
$batasTerlaris = 5;   // Produk Terlaris: Top 5

// Status pesanan yang "sah" untuk pendapatan. Sama dengan keterangan rekap penjualan
// di bawah tabel Produk Terlaris (dan dengan definisi mv_rekap_penjualan_produk).
$statusSah = ["DP Lunas", "Lunas", "Diproses", "Dikirim", "Selesai"];

// ---------- Fungsi tampilan kecil (dipakai kedua tabel) ----------

/** URL foto produk (absolut dari root web); string kosong jika file tidak ada. */
function foto_produk_url(string $foto): string
{
    $nama = basename($foto);
    return is_file(__DIR__ . "/../uploads/merchandise/" . $nama)
        ? "/uploads/merchandise/" . $nama
        : "";
}

/** Kotak foto krem; dibiarkan kosong jika foto tidak ditemukan. */
function html_thumb(?string $foto): string
{
    $url = ($foto !== null && $foto !== "") ? foto_produk_url($foto) : "";
    $img = $url !== ""
        ? '<img src="' . e($url) . '" alt="" width="62" height="70" loading="lazy">'
        : "";
    return '<span class="dash-thumb">' . $img . '</span>';
}

/** Badge kategori. Warna dipetakan dari $warnaKategori agar kategori yang sama selalu berwarna sama. */
function html_kategori(array $warnaKategori, string $nama): string
{
    return '<span class="dash-cat dash-cat--' . (int)($warnaKategori[$nama] ?? 0) . '">' . e($nama) . '</span>';
}

/** Badge status stok. null = produk tidak ditemukan di tabel merchandise. */
function html_status_stok(?int $stok): string
{
    if ($stok === null) {
        return "&ndash;";
    }
    return $stok > 0
        ? '<span class="dash-status dash-status--done">Tersedia</span>'
        : '<span class="dash-status dash-status--out">Habis</span>';
}

/** Tombol Kelola (animasi hover ada di admin-dashboard.css). */
function html_tombol_kelola(string $url, string $namaProduk): string
{
    return '<a class="dash-btn" href="' . e($url) . '" aria-label="Kelola ' . e($namaProduk) . '">'
         . '<svg aria-hidden="true"><use href="#ic-kelola"/></svg>Kelola</a>';
}

// ---------- 1. Kartu statistik ----------
// Satu query untuk keempat angka. Pendapatan hanya dari pesanan berstatus sah;
// Total Pesanan menghitung semua pesanan (sama dengan "Semua" di Kelola Pesanan).

$stat = null;
try {
    $tanda = implode(",", array_fill(0, count($statusSah), "?"));
    $stmt  = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM merchandise)          AS total_produk,
            (SELECT COUNT(*) FROM kategori_merchandise) AS total_kategori,
            (SELECT COUNT(*) FROM pesanan)              AS total_pesanan,
            (SELECT COALESCE(SUM(total_harga), 0)
               FROM pesanan
              WHERE status_pesanan IN ($tanda))         AS pendapatan
    ");
    $stmt->execute($statusSah);
    $stat = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log("Gagal membaca statistik dashboard admin: " . $e->getMessage());
}

$kartu = [
    ["label" => "Total Produk",  "nilai" => $stat["total_produk"]   ?? null, "ikon" => "admin-stat-produk.png",     "uang" => false],
    ["label" => "Kategori",      "nilai" => $stat["total_kategori"] ?? null, "ikon" => "admin-stat-kategori.png",   "uang" => false],
    ["label" => "Total Pesanan", "nilai" => $stat["total_pesanan"]  ?? null, "ikon" => "admin-stat-pesanan.png",    "uang" => false],
    ["label" => "Pendapatan",    "nilai" => $stat["pendapatan"]     ?? null, "ikon" => "admin-stat-pendapatan.png", "uang" => true],
];

// ---------- 2. Warna badge kategori ----------
// Urutan id_kategori -> warna 0..5. Dipetakan lewat nama karena view rekap hanya membawa nama kategori.

$warnaKategori = [];
try {
    foreach ($pdo->query("SELECT nama_kategori FROM kategori_merchandise ORDER BY id_kategori") as $urut => $row) {
        $warnaKategori[$row["nama_kategori"]] = $urut % 6;
    }
} catch (PDOException $e) {
    error_log("Gagal membaca kategori untuk dashboard admin: " . $e->getMessage());
}

// ---------- 3. List Merchandise (produk terbaru) ----------

$produk    = [];
$gagalProd = false;
try {
    $produk = $pdo->query("
        SELECT m.id_merchandise, m.nama_merchandise, m.harga, m.stok, m.foto, k.nama_kategori
        FROM merchandise m
        JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
        ORDER BY m.id_merchandise DESC
        LIMIT " . (int)$batasProduk . "
    ")->fetchAll();
} catch (PDOException $e) {
    $gagalProd = true;
    error_log("Gagal membaca merchandise untuk dashboard admin: " . $e->getMessage());
}

// ---------- 4. Produk Terlaris ----------
// Peringkat, jumlah terjual, dan pendapatan dibaca dari materialized view (cepat, data statistik
// yang diperbolehkan sedikit basi) — kolom yang dipakai sama seperti sebelumnya.
// Foto dan stok tidak ada di view, jadi diambil dari tabel merchandise lewat nama produk.

$terlaris    = [];
$gagalTerlar = false;
try {
    $terlaris = $pdo->query("
        SELECT r.nama_merchandise, r.nama_kategori, r.jumlah_terjual, r.total_pendapatan,
               (SELECT m.foto
                  FROM merchandise m
                 WHERE m.nama_merchandise = r.nama_merchandise
                 ORDER BY m.id_merchandise
                 LIMIT 1) AS foto,
               (SELECT SUM(m.stok)
                  FROM merchandise m
                 WHERE m.nama_merchandise = r.nama_merchandise) AS stok
        FROM mv_rekap_penjualan_produk r
        WHERE r.jumlah_terjual > 0
        ORDER BY r.jumlah_terjual DESC, r.total_pendapatan DESC
        LIMIT " . (int)$batasTerlaris . "
    ")->fetchAll();
} catch (PDOException $e) {
    $gagalTerlar = true;
    error_log("Gagal membaca mv_rekap_penjualan_produk: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Admin - Merchandise Kampus</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <script src="/assets/js/admin-dashboard.js?v=<?= @filemtime(__DIR__ . "/../assets/js/admin-dashboard.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$adminActive = "dashboard";
require __DIR__ . "/../includes/navbar-admin.php";
?>

<!-- Ikon gigi untuk tombol Kelola (dipakai berulang) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="ic-kelola" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </symbol>
</svg>

<main class="dash dash--admin">
    <div class="dash__inner">

        <h1 class="dash__title">Dashboard Admin</h1>
        <p class="dash-greet">Selamat datang, <b><?= e($_SESSION["username"]) ?></b></p>

        <!-- 1. Ringkasan toko (4 kartu statistik) -->
        <section class="dash-stats" aria-label="Ringkasan toko">
            <?php foreach ($kartu as $i => $k): ?>
                <?php
                $ada   = $k["nilai"] !== null;
                $angka = $ada ? (int)round((float)$k["nilai"]) : 0;
                $teks  = !$ada
                    ? "–"
                    : ($k["uang"] ? "Rp " : "") . number_format($angka, 0, ",", ".");

                $kelasAngka = "dash-stat__num";
                if ($k["uang"]) {
                    $kelasAngka .= " dash-stat__num--money" . (strlen($teks) > 12 ? " is-long" : "");
                }
                $atribut = $ada
                    ? ' data-count="' . $angka . '"' . ($k["uang"] ? ' data-prefix="Rp "' : "")
                    : "";
                ?>
                <div class="dash-stat" style="--i:<?= (int)$i ?>">
                    <img class="dash-stat__icon" src="/assets/img/<?= e($k["ikon"]) ?>" alt="" width="113" height="113">
                    <div class="dash-stat__body">
                        <span class="dash-stat__label"><?= e($k["label"]) ?></span>
                        <strong class="<?= $kelasAngka ?>"<?= $atribut ?>><?= e($teks) ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- 2. List Merchandise -->
        <section class="dash-panel dash-orders" aria-labelledby="judul-merchandise">
            <div class="dash-orders__head">
                <span class="dash-orders__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M24 10 4 20l20 10 20-10-20-10Z"/>
                        <path d="M12 26v9c0 3 5.4 6 12 6s12-3 12-6v-9"/>
                        <path d="M44 20v12"/>
                    </svg>
                </span>
                <div>
                    <h2 class="dash-orders__title" id="judul-merchandise">List Merchandise</h2>
                    <p class="dash-orders__sub">List produk yang tersedia di toko</p>
                </div>
            </div>

            <?php if ($gagalProd): ?>

                <div class="dash-empty">
                    <p>Daftar produk belum bisa dimuat. Muat ulang halaman ini sebentar lagi.</p>
                </div>

            <?php elseif (!$produk): ?>

                <div class="dash-empty">
                    <p>Belum ada produk.</p>
                    <p><a href="<?= e($urlKelola) ?>">Tambah produk pertama</a></p>
                </div>

            <?php else: ?>

                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <colgroup><col><col><col><col><col><col><col><col></colgroup>
                        <thead>
                            <tr>
                                <th scope="col">No</th>
                                <th scope="col">Gambar</th>
                                <th scope="col" class="is-left">Nama Produk</th>
                                <th scope="col">Kategori</th>
                                <th scope="col">Harga</th>
                                <th scope="col">Stock</th>
                                <th scope="col">Status</th>
                                <th scope="col">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produk as $i => $p): ?>
                                <tr>
                                    <td data-label="No" class="is-num"><?= $i + 1 ?></td>
                                    <td data-label="Gambar"><?= html_thumb($p["foto"]) ?></td>
                                    <td data-label="Nama Produk" class="is-left"><?= e($p["nama_merchandise"]) ?></td>
                                    <td data-label="Kategori"><?= html_kategori($warnaKategori, $p["nama_kategori"]) ?></td>
                                    <td data-label="Harga" class="is-num is-money">Rp <?= number_format((float)$p["harga"], 0, ",", ".") ?></td>
                                    <td data-label="Stock" class="is-num"><?= (int)$p["stok"] ?></td>
                                    <td data-label="Status"><?= html_status_stok((int)$p["stok"]) ?></td>
                                    <td data-label="Aksi"><?= html_tombol_kelola($urlKelola, $p["nama_merchandise"]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (($stat["total_produk"] ?? 0) > count($produk)): ?>
                    <p class="dash-more">
                        Menampilkan <?= count($produk) ?> dari <?= (int)$stat["total_produk"] ?> produk.
                        <a href="<?= e($urlKelola) ?>">Lihat semua produk</a>
                    </p>
                <?php endif; ?>

            <?php endif; ?>
        </section>

        <!-- 3. Produk Terlaris (struktur sama dengan List Merchandise: Harga -> Pendapatan, Stock -> Terjual) -->
        <section class="dash-panel dash-orders" aria-labelledby="judul-terlaris">
            <div class="dash-orders__head">
                <span class="dash-orders__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 7h18v11a9 9 0 0 1-18 0V7Z"/>
                        <path d="M15 11H7v3a7 7 0 0 0 8.5 6.8"/>
                        <path d="M33 11h8v3a7 7 0 0 1-8.5 6.8"/>
                        <path d="M24 27v8"/>
                        <path d="M16 41h16"/>
                        <path d="M19 35h10v6H19z"/>
                    </svg>
                </span>
                <div>
                    <h2 class="dash-orders__title" id="judul-terlaris">Produk Terlaris</h2>
                    <p class="dash-orders__sub">Produk dengan penjualan terbanyak</p>
                </div>
            </div>

            <?php if ($gagalTerlar): ?>

                <div class="dash-empty">
                    <p>Rekap penjualan belum bisa dimuat. Muat ulang halaman ini sebentar lagi.</p>
                </div>

            <?php elseif (!$terlaris): ?>

                <div class="dash-empty">
                    <p>Belum ada penjualan.</p>
                </div>

            <?php else: ?>

                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <colgroup><col><col><col><col><col><col><col><col></colgroup>
                        <thead>
                            <tr>
                                <th scope="col">No</th>
                                <th scope="col">Gambar</th>
                                <th scope="col" class="is-left">Nama Produk</th>
                                <th scope="col">Kategori</th>
                                <th scope="col">Pendapatan</th>
                                <th scope="col">Terjual</th>
                                <th scope="col">Status</th>
                                <th scope="col">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($terlaris as $i => $t): ?>
                                <tr>
                                    <td data-label="No" class="is-num"><?= $i + 1 ?></td>
                                    <td data-label="Gambar"><?= html_thumb($t["foto"]) ?></td>
                                    <td data-label="Nama Produk" class="is-left"><?= e($t["nama_merchandise"]) ?></td>
                                    <td data-label="Kategori"><?= html_kategori($warnaKategori, $t["nama_kategori"]) ?></td>
                                    <td data-label="Pendapatan" class="is-num is-money">Rp <?= number_format((float)$t["total_pendapatan"], 0, ",", ".") ?></td>
                                    <td data-label="Terjual" class="is-num"><?= (int)$t["jumlah_terjual"] ?></td>
                                    <td data-label="Status"><?= html_status_stok($t["stok"] === null ? null : (int)$t["stok"]) ?></td>
                                    <td data-label="Aksi"><?= html_tombol_kelola($urlKelola, $t["nama_merchandise"]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

            <p class="dash-note">Rekap penjualan hanya dihitung dari pesanan yang sah (DP Lunas, Lunas, Diproses, Dikirim, Selesai).</p>
        </section>

    </div>
</main>

</body>
</html>