<?php

/**
 * Admin — laporan (unduh Excel): penjualan per hari, rekap produk, pesanan pre order, mutasi dompet.
 * Filter periode lewat tanggal; pratinjau ringkas di halaman, detail lengkap ada di file .xlsx.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/xlsx.php";
require_admin();

const STATUS_SAH = ["DP Lunas", "Lunas", "Diproses", "Siap Diambil", "Selesai"];

if (!function_exists("format_rupiah")) {
    function format_rupiah(float $angka): string
    {
        return "Rp " . number_format($angka, 0, ",", ".");
    }
}

function tanggal_valid(string $d): bool
{
    $dt = DateTime::createFromFormat("Y-m-d", $d);
    return $dt !== false && $dt->format("Y-m-d") === $d;
}

$dari   = (string)($_GET["dari"] ?? date("Y-m-01"));
$sampai = (string)($_GET["sampai"] ?? date("Y-m-d"));
$error  = "";
if (!tanggal_valid($dari) || !tanggal_valid($sampai)) {
    $error  = "Tanggal tidak valid.";
    $dari   = date("Y-m-01");
    $sampai = date("Y-m-d");
} elseif ($sampai < $dari) {
    $error  = "Tanggal akhir tidak boleh sebelum tanggal awal.";
    $sampai = $dari;
}

$periode  = [":dari" => $dari, ":sampai" => $sampai];
$sah      = "'" . implode("','", STATUS_SAH) . "'";
$data     = [];
$gagalDb  = false;

try {
    // 1. Penjualan per hari (pesanan sah)
    $stmt = $pdo->prepare("
        SELECT tanggal_pesanan::date AS tgl, COUNT(*) AS jumlah_pesanan, SUM(total_harga) AS omzet
        FROM pesanan
        WHERE status_pesanan IN ({$sah})
          AND tanggal_pesanan >= CAST(:dari AS DATE) AND tanggal_pesanan < (CAST(:sampai AS DATE) + 1)
        GROUP BY tanggal_pesanan::date
        ORDER BY tgl
    ");
    $stmt->execute($periode);
    $data["penjualan"] = $stmt->fetchAll();

    // 2. Rekap produk: terjual pada periode + stok/kuota saat ini
    $stmt = $pdo->prepare("
        SELECT m.nama_merchandise, k.nama_kategori, m.ukuran, m.tipe_produk, m.harga, m.stok,
               COALESCE(SUM(d.jumlah), 0) AS terjual, COALESCE(SUM(d.subtotal), 0) AS pendapatan
        FROM merchandise m
        JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
        LEFT JOIN (
            SELECT d.id_merchandise, d.jumlah, d.subtotal
            FROM detail_pesanan d
            JOIN pesanan p ON p.id_pesanan = d.id_pesanan
            WHERE p.status_pesanan IN ({$sah})
              AND p.tanggal_pesanan >= CAST(:dari AS DATE) AND p.tanggal_pesanan < (CAST(:sampai AS DATE) + 1)
        ) d ON d.id_merchandise = m.id_merchandise
        GROUP BY m.id_merchandise, m.nama_merchandise, k.nama_kategori, m.ukuran, m.tipe_produk, m.harga, m.stok
        ORDER BY terjual DESC, m.nama_merchandise
    ");
    $stmt->execute($periode);
    $data["produk"] = $stmt->fetchAll();

    // 3. Pesanan pre order per produk (semua pesanan PO yang tidak dibatalkan pada periode)
    $stmt = $pdo->prepare("
        SELECT m.nama_merchandise, m.ukuran, m.po_tutup, m.tanggal_ambil, m.stok AS sisa_kuota,
               COUNT(DISTINCT p.id_pesanan) AS jumlah_pesanan, COALESCE(SUM(d.jumlah), 0) AS total_item
        FROM merchandise m
        LEFT JOIN detail_pesanan d ON d.id_merchandise = m.id_merchandise
        LEFT JOIN pesanan p ON p.id_pesanan = d.id_pesanan
             AND p.status_pesanan <> 'Dibatalkan'
             AND p.tanggal_pesanan >= CAST(:dari AS DATE) AND p.tanggal_pesanan < (CAST(:sampai AS DATE) + 1)
        WHERE m.tipe_produk = 'Pre Order'
        GROUP BY m.id_merchandise, m.nama_merchandise, m.ukuran, m.po_tutup, m.tanggal_ambil, m.stok
        ORDER BY m.po_tutup DESC, m.nama_merchandise
    ");
    $stmt->execute($periode);
    $data["po"] = $stmt->fetchAll();

    // 4. Mutasi dompet
    $stmt = $pdo->prepare("
        SELECT md.created_at, a.username, md.jenis, md.jumlah, md.saldo_sesudah, md.id_pesanan, md.keterangan
        FROM mutasi_dompet md
        JOIN akun_login a ON a.id_akun = md.id_akun
        WHERE md.created_at >= CAST(:dari AS DATE) AND md.created_at < (CAST(:sampai AS DATE) + 1)
        ORDER BY md.id_mutasi
    ");
    $stmt->execute($periode);
    $data["mutasi"] = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Laporan gagal: " . $e->getMessage());
    $gagalDb = true;
    $error   = $error !== "" ? $error : "Laporan belum bisa dibuat. Pastikan migrasi database (pre_order.sql, self_pickup.sql) sudah dijalankan.";
}

if (isset($_GET["unduh"]) && !$gagalDb && $error === "") {
    $penjualan = [["Tanggal", "Jumlah Pesanan", "Omzet (Rp)"]];
    $totalPesanan = 0;
    $totalOmzet   = 0.0;
    foreach ($data["penjualan"] as $r) {
        $penjualan[] = [$r["tgl"], (int)$r["jumlah_pesanan"], (float)$r["omzet"]];
        $totalPesanan += (int)$r["jumlah_pesanan"];
        $totalOmzet   += (float)$r["omzet"];
    }
    $penjualan[] = ["TOTAL", $totalPesanan, $totalOmzet];

    $produk = [["Produk", "Kategori", "Ukuran", "Jenis", "Harga (Rp)", "Terjual", "Pendapatan (Rp)", "Sisa Stok / Kuota"]];
    foreach ($data["produk"] as $r) {
        $produk[] = [$r["nama_merchandise"], $r["nama_kategori"], $r["ukuran"], $r["tipe_produk"],
                     (float)$r["harga"], (int)$r["terjual"], (float)$r["pendapatan"], (int)$r["stok"]];
    }

    $po = [["Produk", "Ukuran", "Tutup PO", "Hari Pengambilan", "Jumlah Pesanan", "Total Item", "Sisa Kuota"]];
    foreach ($data["po"] as $r) {
        $po[] = [$r["nama_merchandise"], $r["ukuran"], (string)$r["po_tutup"], (string)$r["tanggal_ambil"],
                 (int)$r["jumlah_pesanan"], (int)$r["total_item"], (int)$r["sisa_kuota"]];
    }

    $mutasi = [["Waktu", "Akun", "Jenis", "Jumlah (Rp)", "Saldo Sesudah (Rp)", "ID Pesanan", "Keterangan"]];
    foreach ($data["mutasi"] as $r) {
        $mutasi[] = [substr((string)$r["created_at"], 0, 19), $r["username"], $r["jenis"],
                     (float)$r["jumlah"], (float)$r["saldo_sesudah"], $r["id_pesanan"] !== null ? (int)$r["id_pesanan"] : "", $r["keterangan"] ?? ""];
    }

    catat_log_admin($pdo, "Unduh", "Laporan", null, "periode {$dari} s/d {$sampai}");
    xlsx_kirim("laporan_{$dari}_{$sampai}.xlsx", [
        "Penjualan"      => $penjualan,
        "Rekap Produk"   => $produk,
        "Pre Order"      => $po,
        "Mutasi Dompet"  => $mutasi,
    ]);
}

$totalOmzetTampil   = 0.0;
$totalPesananTampil = 0;
foreach (($data["penjualan"] ?? []) as $r) {
    $totalOmzetTampil   += (float)$r["omzet"];
    $totalPesananTampil += (int)$r["jumlah_pesanan"];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-kategori.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-kategori.css") ?>">
</head>
<body class="page-dashboard">
<?php
$adminActive     = "laporan";
$adminNavRingkas = true;
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-kategori">
    <div class="kt">

        <header class="kt-head">
            <nav class="kt-crumb" aria-label="Breadcrumb">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/laporan.php" aria-current="page">Laporan</a></span>
            </nav>
            <div class="kt-title">
                <div>
                    <h1>Laporan</h1>
                    <p>Penjualan, rekap produk, pesanan pre order, dan mutasi dompet dalam satu file Excel</p>
                </div>
            </div>
        </header>

        <section class="kt-card" aria-label="Laporan">

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="GET">
                <label for="dari">Dari</label>
                <input type="date" id="dari" name="dari" value="<?= e($dari) ?>" required>
                <label for="sampai">Sampai</label>
                <input type="date" id="sampai" name="sampai" value="<?= e($sampai) ?>" required>
                <button type="submit">Tampilkan</button>
                <button type="submit" name="unduh" value="1">Unduh Excel</button>
            </form>

            <?php if (!$gagalDb): ?>
                <p>
                    Periode <b><?= e($dari) ?></b> s/d <b><?= e($sampai) ?></b>:
                    <b><?= $totalPesananTampil ?></b> pesanan sah, omzet <b><?= e(format_rupiah($totalOmzetTampil)) ?></b>,
                    <b><?= count($data["mutasi"]) ?></b> mutasi dompet,
                    <b><?= count($data["po"]) ?></b> produk Pre Order.
                </p>

                <h2>Rekap Produk</h2>
                <?php if (!$data["produk"]): ?>
                    <p>Belum ada produk.</p>
                <?php else: ?>
                    <div class="kt-table-wrap">
                        <table class="kt-table">
                            <caption class="kt-sr">Rekap produk</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Produk</th>
                                    <th scope="col">Jenis</th>
                                    <th scope="col">Terjual</th>
                                    <th scope="col">Pendapatan</th>
                                    <th scope="col">Sisa Stok</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data["produk"] as $r): ?>
                                    <tr>
                                        <td data-label="Produk"><?= e($r["nama_merchandise"]) ?> (<?= e($r["ukuran"]) ?>)</td>
                                        <td data-label="Jenis"><?= e($r["tipe_produk"]) ?></td>
                                        <td data-label="Terjual"><?= (int)$r["terjual"] ?></td>
                                        <td data-label="Pendapatan"><?= e(format_rupiah((float)$r["pendapatan"])) ?></td>
                                        <td data-label="Sisa Stok"><?= (int)$r["stok"] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </section>
    </div>
</main>

</body>
</html>
