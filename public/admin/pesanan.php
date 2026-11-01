<?php

/**
 * Admin — daftar semua pesanan (dengan filter status).
 * Klik nomor pesanan untuk detail: ubah status & kelola pengiriman.
 *
 * Tampilan mengikuti desain UI/UX (navbar admin, tab filter, kartu tabel);
 * auth, query, dan filter status tetap dari tim backend.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

$daftarStatus = [
    "Menunggu Pembayaran", "Menunggu DP", "DP Lunas", "Lunas",
    "Diproses", "Dikirim", "Selesai", "Dibatalkan",
];

$statusFilter = in_array($_GET["status"] ?? "", $daftarStatus, true)
    ? $_GET["status"]
    : "";

$sql = "
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga, p.status_pesanan,
           a.username, m.nama AS nama_mhs, m.nim,
           (SELECT COALESCE(SUM(d.jumlah), 0)
            FROM detail_pesanan d
            WHERE d.id_pesanan = p.id_pesanan) AS total_item,
           (SELECT pb.status_bayar
            FROM pembayaran pb
            WHERE pb.id_pesanan = p.id_pesanan
            ORDER BY pb.id_pembayaran DESC
            LIMIT 1) AS status_bayar
    FROM pesanan p
    JOIN akun_login a ON a.id_akun = p.id_akun
    LEFT JOIN mahasiswa m ON m.id_akun = p.id_akun
";
$params = [];
if ($statusFilter !== "") {
    $sql .= " WHERE p.status_pesanan = :status";
    $params[":status"] = $statusFilter;
}
$sql .= " ORDER BY p.id_pesanan DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pesanan = $stmt->fetchAll();

$flash = take_flash();

// ---------- Tampilan (tidak memengaruhi data) ----------

// Badge status pesanan: status => [warna, ikon]. Warna didefinisikan di admin-pesanan.css (.po-badge--*).
$badgePesanan = [
    "Menunggu Pembayaran" => ["wait",    "ic-clock"],
    "Menunggu DP"         => ["wait",    "ic-clock"],
    "Menunggu Pelunasan"  => ["wait",    "ic-clock"],
    "DP Lunas"            => ["done",    "ic-check"],
    "Lunas"               => ["done",    "ic-check"],
    "Diproses"            => ["process", "ic-gear"],
    "Dikirim"             => ["ship",    "ic-truck"],
    "Selesai"             => ["done",    "ic-check"],
    "Dibatalkan"          => ["off",     "ic-x"],
];

// Badge status pembayaran (nilai dari tabel pembayaran / Midtrans).
$badgeBayar = [
    "Menunggu"    => ["wait", "ic-clock"],
    "Berhasil"    => ["done", "ic-check"],
    "Gagal"       => ["fail", "ic-x"],
    "Kedaluwarsa" => ["off",  "ic-clock"],
];

/** Badge berikon. Status yang tidak dikenal tetap tampil (abu-abu, tanpa ikon). */
function html_badge(array $peta, ?string $teks): string
{
    if ($teks === null || $teks === "") {
        return '<span class="po-none">&ndash;</span>';
    }
    [$warna, $ikon] = $peta[$teks] ?? ["off", null];
    $svg = $ikon !== null
        ? '<svg aria-hidden="true" focusable="false"><use href="#' . $ikon . '"/></svg>'
        : "";
    return '<span class="po-badge po-badge--' . $warna . '">' . $svg . e($teks) . '</span>';
}

/** Format Rupiah mengikuti desain: "Rp. 100.000". */
function format_rupiah(float $angka): string
{
    return "Rp. " . number_format($angka, 0, ",", ".");
}

/** Alamat tab filter. Status dikodekan agar spasi ("Menunggu Pembayaran") aman di URL. */
function url_filter(string $status = ""): string
{
    return "/admin/pesanan.php" . ($status !== "" ? "?" . http_build_query(["status" => $status]) : "");
}

$tabFilter = ["" => "Semua"];
foreach ($daftarStatus as $s) {
    $tabFilter[$s] = $s;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Pesanan - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-pesanan.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-pesanan.css") ?>">
    <script src="/assets/js/admin-pesanan.js?v=<?= @filemtime(__DIR__ . "/../assets/js/admin-pesanan.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$adminActive     = "pesanan";
$adminNavRingkas = true;   // desain Kelola Pesanan: 4 menu + ikon akun saja
require __DIR__ . "/../includes/navbar-admin.php";
?>

<!-- Ikon badge status (dipakai berulang lewat <use>) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="ic-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="9.5"/>
        <path d="M12 6.8V12l3.4 2"/>
    </symbol>
    <symbol id="ic-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="9.5"/>
        <path d="m7.8 12.4 2.9 2.9 5.6-6"/>
    </symbol>
    <symbol id="ic-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="9.5"/>
        <path d="m9 9 6 6M15 9l-6 6"/>
    </symbol>
    <symbol id="ic-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 6.5h11.5v10H2z"/>
        <path d="M13.5 10h4.2l3.3 3.4v3.1h-7.5"/>
        <circle cx="7" cy="18" r="2"/>
        <circle cx="17.5" cy="18" r="2"/>
    </symbol>
    <symbol id="ic-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </symbol>
</svg>

<main class="dash dash-pesanan">
    <div class="po">

        <!-- Kepala halaman: breadcrumb + ikon + judul -->
        <header class="po-head">
            <nav class="po-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/pesanan.php" aria-current="page">Kelola Pesanan</a></span>
            </nav>
            <div class="po-title">
                <span class="po-title__icon" aria-hidden="true"><img src="/assets/img/icon-package.png" alt="" width="128" height="128"></span>
                <div>
                    <h1>Kelola Pesanan Merchandise</h1>
                    <p>Kelola pesanan merchandise JTI</p>
                </div>
            </div>
        </header>

        <section class="po-card" aria-label="Daftar pesanan">

            <?php if ($flash): ?>
                <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="<?= ($flash["type"] ?? "") === "sukses" ? "status" : "alert" ?>">
                    <?= e($flash["msg"]) ?>
                </div>
            <?php endif; ?>

            <!-- Filter status: tiap tab = tautan ?status=..., jadi data tetap difilter oleh query backend di atas -->
            <nav class="po-tabs" aria-label="Filter status pesanan">
                <?php foreach ($tabFilter as $nilai => $label): ?>
                    <?php $aktif = ((string)$nilai === $statusFilter); ?>
                    <a class="po-tab<?= $aktif ? " is-active" : "" ?>" href="<?= e(url_filter((string)$nilai)) ?>"<?= $aktif ? ' aria-current="page"' : "" ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if (!$pesanan): ?>

                <div class="dash-empty">
                    <p>Tidak ada pesanan<?= $statusFilter !== "" ? " dengan status tersebut." : "." ?></p>
                    <?php if ($statusFilter !== ""): ?>
                        <p><a href="<?= e(url_filter()) ?>">Tampilkan semua pesanan</a></p>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <div class="po-table-wrap">
                    <table class="po-table">
                        <caption class="po-sr">
                            Daftar pesanan<?= $statusFilter !== "" ? " dengan status " . e($statusFilter) : "" ?>
                        </caption>
                        <colgroup><col><col><col><col><col><col><col></colgroup>
                        <thead>
                            <tr>
                                <th scope="col">No</th>
                                <th scope="col" class="is-left">Tanggal</th>
                                <th scope="col" class="is-left">Pembeli</th>
                                <th scope="col">Item</th>
                                <th scope="col">Status Pesanan</th>
                                <th scope="col">Pembayaran</th>
                                <th scope="col" class="is-right">Total Harga</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pesanan as $i => $p): ?>
                                <?php $urlDetail = "/admin/pesanan-detail.php?id=" . (int)$p["id_pesanan"]; ?>
                                <tr style="--i:<?= (int)$i ?>" data-href="<?= e($urlDetail) ?>">
                                    <td data-label="No Pesanan">
                                        <a class="po-no" href="<?= e($urlDetail) ?>" aria-label="Detail pesanan nomor <?= (int)$p["id_pesanan"] ?>">#<?= (int)$p["id_pesanan"] ?></a>
                                    </td>
                                    <td data-label="Tanggal" class="is-left is-num"><?= e(substr($p["tanggal_pesanan"], 0, 16)) ?></td>
                                    <td data-label="Pembeli" class="is-left">
                                        <div class="po-who">
                                            <span class="po-buyer"><?= e($p["nama_mhs"] ?? $p["username"]) ?></span>
                                            <small class="po-nim"><?= e($p["nim"] ?? "-") ?></small>
                                        </div>
                                    </td>
                                    <td data-label="Item" class="is-num"><?= (int)$p["total_item"] ?></td>
                                    <td data-label="Status Pesanan"><?= html_badge($badgePesanan, $p["status_pesanan"]) ?></td>
                                    <td data-label="Pembayaran"><?= html_badge($badgeBayar, $p["status_bayar"]) ?></td>
                                    <td data-label="Total Harga" class="is-right"><span class="po-total"><?= e(format_rupiah((float)$p["total_harga"])) ?></span></td>
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
