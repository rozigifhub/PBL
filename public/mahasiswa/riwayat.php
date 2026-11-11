<?php

/**
 * Riwayat pesanan mahasiswa (terbaru dulu).
 * Tiap baris menuju detail: pesanan.php?id=...
 *
 * Tampilan mengikuti desain UI/UX (kartu krem, tab filter status, tabel bergaya kartu);
 * auth, query, dan variabel looping tetap dari tim backend. Tab status memfilter baris
 * di browser (assets/js/riwayat.js), jadi tidak ada query tambahan per tab.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
// Tamu (belum login) dilempar ke login.php?error=menu (ini target menu PESANAN di navbar).
require_mahasiswa("../login.php", "menu");

// [TAMBAHAN UI] Dua kolom baru di query ini (nama_produk, jenis_produk) hanya untuk kolom
// "Nama Produk" di desain. Kolom, WHERE, dan ORDER BY lainnya TIDAK diubah.
//   nama_produk  = nama produk pertama di pesanan (urut abjad)
//   jenis_produk = jumlah jenis produk di pesanan (untuk teks "+N lainnya")
$stmt = $pdo->prepare("
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga, p.status_pesanan, p.tipe_pesanan,
           (SELECT COALESCE(SUM(d.jumlah), 0)
            FROM detail_pesanan d
            WHERE d.id_pesanan = p.id_pesanan) AS total_item,
           (SELECT m.nama_merchandise
            FROM detail_pesanan d
            JOIN merchandise m ON m.id_merchandise = d.id_merchandise
            WHERE d.id_pesanan = p.id_pesanan
            ORDER BY m.nama_merchandise
            LIMIT 1) AS nama_produk,
           (SELECT COUNT(*)
            FROM detail_pesanan d
            WHERE d.id_pesanan = p.id_pesanan) AS jenis_produk,
           (SELECT pb.status_bayar
            FROM pembayaran pb
            WHERE pb.id_pesanan = p.id_pesanan
            ORDER BY pb.id_pembayaran DESC
            LIMIT 1) AS status_bayar
    FROM pesanan p
    WHERE p.id_akun = :id_akun
    ORDER BY p.id_pesanan DESC
");
$stmt->execute([":id_akun" => $_SESSION["id_akun"]]);
$pesanan = $stmt->fetchAll();

// ---------- Tampilan saja (tidak memengaruhi data) ----------

// Tab filter: kunci => [label, daftar status_pesanan yang ditampilkan di tab itu].
// Daftar kosong = semua pesanan. Pesanan "Dibatalkan" hanya muncul di tab "Semua" (desain tidak punya tab-nya).
$tabRiwayat = [
    "semua"               => ["Semua",               []],
    "menunggu-pembayaran" => ["Menunggu Pembayaran", ["Menunggu Pembayaran", "Menunggu Pelunasan"]],
    "menunggu-dp"         => ["Menunggu DP",         ["Menunggu DP"]],
    "lunas"               => ["Lunas",               ["DP Lunas", "Lunas"]],
    "diproses"            => ["Diproses",            ["Diproses"]],
    "dikirim"             => ["Dikirim",             ["Dikirim"]],
    "selesai"             => ["Selesai",             ["Selesai"]],
];

// Warna badge status: status => kelas CSS (.rw-badge--* di riwayat.css). Status yang tidak dikenal tampil abu-abu.
$kelasBadge = [
    "Menunggu Pembayaran" => "wait",
    "Menunggu DP"         => "wait",
    "Menunggu Pelunasan"  => "wait",
    "DP Lunas"            => "done",
    "Lunas"               => "done",
    "Diproses"            => "process",
    "Dikirim"             => "ship",
    "Selesai"             => "done",
    "Dibatalkan"          => "off",
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riwayat Pesanan - Merchandise Kampus</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/riwayat.css?v=<?= @filemtime(__DIR__ . "/../assets/css/riwayat.css") ?>">
    <script src="/assets/js/riwayat.js?v=<?= @filemtime(__DIR__ . "/../assets/js/riwayat.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$mhsActive = "pesanan";
require __DIR__ . "/../includes/navbar.php";
?>

<!-- Ikon tombol Detail (dipakai berulang lewat <use>) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="ic-detail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </symbol>
</svg>

<main class="dash dash-riwayat">
    <div class="rw">

        <!-- Kepala halaman: breadcrumb + ikon toga + judul -->
        <header class="rw-head">
            <nav class="rw-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
                <span><a href="/mahasiswa/dashboard.php">Dashboard</a> / <a href="/mahasiswa/riwayat.php" aria-current="page">Pesanan</a></span>
            </nav>
            <div class="rw-title">
                <span class="rw-title__icon" aria-hidden="true">
                    <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M32 12 3 25.5 32 39 61 25.5z"/>
                        <path d="M15 33v12c0 4.2 7.6 8 17 8s17-3.8 17-8V33"/>
                        <path d="M56 28v14"/>
                    </svg>
                </span>
                <div>
                    <h1>Riwayat Pesanan</h1>
                    <p>List pesanan Anda</p>
                </div>
            </div>
        </header>

        <section class="rw-card" aria-label="Daftar pesanan">

            <?php if (!$pesanan): ?>

                <!-- Belum ada pesanan sama sekali (logika asli dipertahankan) -->
                <div class="dash-empty rw-empty rw-empty--first">
                    <p>Belum ada pesanan.</p>
                    <p><a href="/mahasiswa/katalog.php"><b>Mulai Belanja &rarr;</b></a></p>
                </div>

            <?php else: ?>

                <!-- Tab filter status. Tiap tab membawa daftar status (data-filter) yang dicocokkan JS dengan data-status pada baris tabel. -->
                <div class="rw-tabs" data-rw-tabs role="tablist" aria-label="Filter status pesanan">
                    <span class="rw-tabs__slider" aria-hidden="true"></span>
                    <?php foreach ($tabRiwayat as $kunci => [$label, $daftarStatus]): ?>
                        <?php $pertama = ($kunci === "semua"); ?>
                        <button type="button" class="rw-tab<?= $pertama ? " is-active" : "" ?>" role="tab" id="tab-<?= e($kunci) ?>"
                                data-key="<?= e($kunci) ?>" data-filter="<?= e(implode("|", $daftarStatus)) ?>"
                                aria-selected="<?= $pertama ? "true" : "false" ?>" aria-controls="rw-panel" tabindex="<?= $pertama ? "0" : "-1" ?>"><?= e($label) ?></button>
                    <?php endforeach; ?>
                </div>

                <div id="rw-panel" role="tabpanel">
                    <p class="rw-sr" data-rw-info role="status"></p>

                    <div class="rw-table-wrap" data-rw-wrap>
                        <table class="rw-table" data-rw-table>
                            <caption class="rw-sr">Daftar pesanan Anda</caption>
                            <colgroup><col><col><col><col><col><col></colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">No. Pesanan</th>
                                    <th scope="col">Tanggal</th>
                                    <th scope="col">Nama Produk</th>
                                    <th scope="col">Harga</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>

                                <!-- ============ MULAI LOOPING PHP ============
                                     Ini looping asli dari backend: foreach ($pesanan as $p).
                                     Satu iterasi = satu <tr>. Kalau looping-mu diganti (while / nama variabel lain),
                                     ganti baris foreach dan endforeach saja; isi <tr> di bawah memakai $p["..."] yang sama
                                     seperti kode lama (id_pesanan, tanggal_pesanan, total_harga, status_pesanan, status_bayar).
                                     data-status WAJIB tetap ada di <tr>: dipakai tab filter. -->
                                <?php foreach ($pesanan as $p): ?>
                                    <?php
                                    // Variabel bantu tampilan untuk baris ini
                                    $idPesanan = (int)$p["id_pesanan"];
                                    $urlDetail = "/mahasiswa/pesanan.php?id=" . $idPesanan;   // endpoint detail pesanan (sama dengan kode lama)
                                    $kelas     = $kelasBadge[$p["status_pesanan"]] ?? "off";
                                    $totalItem = (int)$p["total_item"];
                                    $lainnya   = max(0, (int)$p["jenis_produk"] - 1);
                                    ?>
                                    <tr data-href="<?= e($urlDetail) ?>" data-status="<?= e($p["status_pesanan"]) ?>">

                                        <!-- No. Pesanan: ID pesanan, tautan ke detail -->
                                        <td data-label="No. Pesanan">
                                            <a class="rw-no" href="<?= e($urlDetail) ?>">#<?= $idPesanan ?></a>
                                        </td>

                                        <!-- Tanggal -->
                                        <td data-label="Tanggal" class="is-num"><?= e(substr($p["tanggal_pesanan"], 0, 16)) ?></td>

                                        <!-- Nama Produk (dulu kolom "Item"): nama produk pertama + "+N lainnya"; jumlah barang tampil kecil bila lebih dari 1 -->
                                        <td data-label="Nama Produk">
                                            <span class="rw-prod">
                                                <?= e($p["nama_produk"] ?? "-") ?>
                                                <?php if ($lainnya > 0): ?><span class="rw-more">+<?= $lainnya ?> lainnya</span><?php endif; ?>
                                                <?php if ($totalItem > 1): ?><small><?= $totalItem ?> item</small><?php endif; ?>
                                            </span>
                                        </td>

                                        <!-- Harga (dulu kolom "Total"): format Rupiah sama dengan kode lama -->
                                        <td data-label="Harga" class="is-num"><span class="rw-price">Rp <?= number_format((float)$p["total_harga"], 0, ",", ".") ?></span></td>

                                        <!-- Status: badge pil berwarna + keterangan status pembayaran (dari status_bayar) -->
                                        <td data-label="Status">
                                            <div class="rw-state">
                                                <span class="rw-badge rw-badge--<?= $kelas ?>"><?= e($p["status_pesanan"]) ?></span>
                                                <?php if (!empty($p["status_bayar"])): ?>
                                                    <small class="rw-pay">Pembayaran: <?= e($p["status_bayar"]) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Aksi (kolom baru): tombol Detail ke pesanan.php?id=... -->
                                        <td data-label="Aksi">
                                            <a class="rw-btn" href="<?= e($urlDetail) ?>" aria-label="Lihat detail pesanan #<?= $idPesanan ?>">
                                                <svg aria-hidden="true" focusable="false"><use href="#ic-detail"/></svg>
                                                Detail
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- ============ AKHIR LOOPING PHP ============ -->

                            </tbody>
                        </table>
                    </div>

                    <!-- Muncul (diatur JS) bila tab yang dipilih tidak punya pesanan -->
                    <div class="dash-empty rw-empty" data-rw-empty hidden>
                        <p data-rw-empty-text>Tidak ada pesanan dengan status ini.</p>
                        <p><button type="button" class="rw-link" data-rw-reset>Tampilkan semua pesanan</button></p>
                    </div>
                </div>

            <?php endif; ?>

        </section>

        <!-- Menu cepat (paling bawah): sama dengan dashboard -->
        <nav class="dash-quick" aria-label="Menu cepat">
            <a class="dash-quick__main" href="katalog.php">Lihat Katalog</a>
            <a href="dashboard.php">Lihat Dashboard</a>
            <a href="profil.php">Kelola Profil</a>
            <a class="dash-quick__out" href="/logout.php">Logout</a>
        </nav>
    </div>
</main>

</body>
</html>
