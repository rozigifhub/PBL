<?php

// Dashboard mahasiswa: data diri + riwayat pesanan + navigasi.

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

// Profil mahasiswa (join akun_login untuk email)
$stmt = $pdo->prepare("
    SELECT m.nim, m.nama, m.no_hp, m.alamat, a.email
    FROM mahasiswa m
    JOIN akun_login a ON a.id_akun = m.id_akun
    WHERE m.id_akun = :id_akun
");
$stmt->execute([":id_akun" => $_SESSION["id_akun"]]);
$profil = $stmt->fetch();

// Riwayat pesanan mahasiswa, terbaru dulu.
// Query ini sama persis dengan yang dipakai riwayat.php.
$stmt = $pdo->prepare("
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga, p.status_pesanan, p.tipe_pesanan,
           (SELECT COALESCE(SUM(d.jumlah), 0)
            FROM detail_pesanan d
            WHERE d.id_pesanan = p.id_pesanan) AS total_item,
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
$semuaPesanan = $stmt->fetchAll();

// Dashboard hanya menampilkan pesanan terbaru; selebihnya ada di riwayat.php.
// Ubah angka ini untuk menampilkan lebih banyak / lebih sedikit baris.
$batasPesanan = 5;
$pesanan      = array_slice($semuaPesanan, 0, $batasPesanan);

// Ringkasan pesanan untuk 4 kartu statistik.
// Dihitung di PHP dari $semuaPesanan (data yang sudah diambil di atas), jadi TIDAK ada query tambahan.
// Dihitung dari SEMUA pesanan, bukan dari $pesanan yang dipotong $batasPesanan.
$jumlahStatus = ["Diproses" => 0, "Dikirim" => 0, "Selesai" => 0];
foreach ($semuaPesanan as $row) {
    if (isset($jumlahStatus[$row["status_pesanan"]])) {
        $jumlahStatus[$row["status_pesanan"]]++;
    }
}
$ringkasan = [
    ["label" => "Total Pesanan",    "jumlah" => count($semuaPesanan),             "ikon" => "stat-total.png"],
    ["label" => "Pesanan Diproses", "jumlah" => $jumlahStatus["Diproses"], "ikon" => "stat-diproses.png"],
    ["label" => "Pesanan Dikirim",  "jumlah" => $jumlahStatus["Dikirim"],  "ikon" => "stat-dikirim.png"],
    ["label" => "Pesanan Selesai",  "jumlah" => $jumlahStatus["Selesai"],  "ikon" => "stat-selesai.png"],
];

// Warna badge per status pesanan (hanya untuk tampilan, tidak memengaruhi data).
$kelasStatus = [
    "Menunggu Pembayaran" => "wait",
    "Menunggu DP"         => "wait",
    "Menunggu Pelunasan"  => "wait",
    "DP Lunas"            => "done",
    "Lunas"               => "done",
    "Diproses"            => "process",
    "Dikirim"             => "ship",
    "Selesai"             => "done",
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Mahasiswa - Merchandise Kampus</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body class="page-dashboard">
<?php require __DIR__ . "/../includes/navbar.php"; ?>

<!-- Ikon yang dipakai berulang (tombol Detail) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="ic-detail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </symbol>
</svg>

<main class="dash">
    <div class="dash__inner">

        <h1 class="dash__title">Dashboard Mahasiswa</h1>

        <!-- 1. Greeting & Data Diri -->
        <section class="dash-panel dash-profile" aria-labelledby="judul-data-diri">
            <p class="dash-hello">
                <span class="dash-avatar" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>
                    </svg>
                </span>
                <span>Selamat datang, <b><?= e($_SESSION["username"]) ?></b></span>
            </p>

            <h2 class="dash-profile__title" id="judul-data-diri">Data Diri</h2>

            <dl class="dash-data">
                <div class="dash-data__item">
                    <dt>NIM</dt>
                    <dd><?= e($profil["nim"] ?? "-") ?></dd>
                </div>
                <div class="dash-data__item">
                    <dt>Nama</dt>
                    <dd><?= e($profil["nama"] ?? "-") ?></dd>
                </div>
                <div class="dash-data__item">
                    <dt>Email</dt>
                    <dd><?= e($profil["email"] ?? "-") ?></dd>
                </div>
                <div class="dash-data__item">
                    <dt>No. HP</dt>
                    <dd><?= e($profil["no_hp"] ?? "-") ?></dd>
                </div>
                <div class="dash-data__item dash-data__item--wide">
                    <dt>Alamat</dt>
                    <dd>
                        <?= $profil["alamat"] ? nl2br(e($profil["alamat"])) : "<i>Belum ada — isi di halaman profil</i>" ?>
                    </dd>
                </div>
            </dl>
        </section>

        <!-- 2. Ringkasan pesanan (4 kartu statistik) -->
        <section class="dash-stats" aria-label="Ringkasan pesanan">
            <?php foreach ($ringkasan as $k): ?>
                <div class="dash-stat">
                    <img class="dash-stat__icon" src="/assets/img/<?= e($k["ikon"]) ?>" alt="" width="113" height="113">
                    <div class="dash-stat__body">
                        <span class="dash-stat__label"><?= e($k["label"]) ?></span>
                        <strong class="dash-stat__num"><?= (int)$k["jumlah"] ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- 3. Riwayat / List Pesanan -->
        <section class="dash-panel dash-orders" aria-labelledby="judul-riwayat">
            <div class="dash-orders__head">
                <span class="dash-orders__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M24 10 4 20l20 10 20-10-20-10Z"/>
                        <path d="M12 26v9c0 3 5.4 6 12 6s12-3 12-6v-9"/>
                        <path d="M44 20v12"/>
                    </svg>
                </span>
                <div>
                    <h2 class="dash-orders__title" id="judul-riwayat">Riwayat Pesanan</h2>
                    <p class="dash-orders__sub">Pesanan terbaru Anda</p>
                </div>
            </div>

            <?php if (!$pesanan): ?>

                <div class="dash-empty">
                    <p>Belum ada pesanan.</p>
                    <p><a href="/mahasiswa/katalog.php">Mulai Belanja &rarr;</a></p>
                </div>

            <?php else: ?>

                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th scope="col">No. Pesanan</th>
                                <th scope="col">Tanggal</th>
                                <th scope="col" class="is-center">Item</th>
                                <th scope="col" class="is-right">Total</th>
                                <th scope="col">Status</th>
                                <th scope="col">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pesanan as $p): ?>
                                <?php
                                $idPesanan  = (int)$p["id_pesanan"];
                                $belumBayar = $p["status_pesanan"] === "Menunggu Pembayaran";
                                $kelas      = $kelasStatus[$p["status_pesanan"]] ?? "neutral";
                                ?>
                                <tr>
                                    <td data-label="No. Pesanan">
                                        <a class="dash-table__no" href="/mahasiswa/pesanan.php?id=<?= $idPesanan ?>">#<?= $idPesanan ?></a>
                                    </td>
                                    <td data-label="Tanggal" class="is-num"><?= e(substr($p["tanggal_pesanan"], 0, 16)) ?></td>
                                    <td data-label="Item" class="is-center is-num"><?= (int)$p["total_item"] ?></td>
                                    <td data-label="Total" class="is-right is-num">Rp <?= number_format((float)$p["total_harga"], 0, ",", ".") ?></td>
                                    <td data-label="Status">
                                        <div class="dash-table__state">
                                            <?php if ($belumBayar): ?>
                                                <span class="dash-status dash-status--<?= $kelas ?> dash-status--unpaid"><span aria-hidden="true">⏳</span> Belum dibayar</span>
                                            <?php else: ?>
                                                <span class="dash-status dash-status--<?= $kelas ?>"><?= e($p["status_pesanan"]) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($p["status_bayar"])): ?>
                                                <small class="dash-table__pay">Pembayaran: <?= e($p["status_bayar"]) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Aksi">
                                        <a class="dash-btn" href="/mahasiswa/pesanan.php?id=<?= $idPesanan ?>" aria-label="Lihat detail pesanan #<?= $idPesanan ?>">
                                            <svg aria-hidden="true"><use href="#ic-detail"/></svg>
                                            Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($semuaPesanan) > $batasPesanan): ?>
                    <p class="dash-more">
                        Menampilkan <?= count($pesanan) ?> dari <?= count($semuaPesanan) ?> pesanan.
                        <a href="/mahasiswa/riwayat.php">Lihat semua riwayat</a>
                    </p>
                <?php endif; ?>

            <?php endif; ?>
        </section>

        <!-- 4. Menu cepat -->
        <nav class="dash-quick" aria-label="Menu cepat">
            <a class="dash-quick__main" href="katalog.php">Lihat Katalog</a>
            <a href="riwayat.php">Riwayat Pesanan</a>
            <a href="profil.php">Kelola Profil</a>
            <a class="dash-quick__out" href="/logout.php">Logout</a>
        </nav>

    </div>
</main>

</body>
</html>
