<?php

/**
 * Admin — daftar semua pesanan (dengan filter status).
 * Klik nomor pesanan untuk detail: ubah status & kelola pengiriman.
 *
 * Tampilan mengikuti desain UI/UX (navbar admin, kolom pencarian, tombol filter "Semua" + dropdown status,
 * checkbox "Pilih Semua", kartu tabel); auth, query, dan filter status tetap dari tim backend.
 * Filter status tetap dijalankan query di atas (opsi dropdown = tautan ?status=...); pencarian nama pembeli
 * menyaring baris di browser (assets/js/pesanan-tools.js).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

$daftarStatus = [
    "Menunggu Pembayaran", "Menunggu DP", "DP Lunas", "Lunas",
    "Diproses", "Siap Diambil", "Selesai", "Dibatalkan",
];

$statusFilter = in_array($_GET["status"] ?? "", $daftarStatus, true)
    ? $_GET["status"]
    : "";

// "Hapus" di sini = arsipkan: pesanan hilang dari daftar aktif, tapi datanya
// (pembayaran, mutasi dompet, laporan) tidak disentuh. Tersedia tautan "Arsip" untuk memulihkan.
$tampilArsip = isset($_GET["arsip"]);
$error       = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action = $_POST["action"] ?? "";
        if ($action === "arsipkan_terpilih" || $action === "pulihkan_terpilih") {
            $arsipkan = $action === "arsipkan_terpilih";
            $ids = array_values(array_unique(array_filter(
                array_map("intval", (array)($_POST["ids"] ?? [])),
                static fn($v) => $v > 0
            )));

            if (!$ids) {
                set_flash("gagal", "Belum ada pesanan yang dipilih.");
            } else {
                try {
                    // TRUE/FALSE ditulis literal (bukan parameter) agar aman untuk kolom boolean lewat PDO pgsql;
                    // $arsipkan berasal dari nilai action di server, bukan input pengguna.
                    $placeholder = implode(",", array_fill(0, count($ids), "?"));
                    $valBaru = $arsipkan ? "TRUE" : "FALSE";
                    $valLama = $arsipkan ? "FALSE" : "TRUE";
                    $stmt = $pdo->prepare("
                        UPDATE pesanan
                        SET diarsipkan = {$valBaru}, diarsipkan_pada = " . ($arsipkan ? "now()" : "NULL") . "
                        WHERE id_pesanan IN ($placeholder) AND diarsipkan = {$valLama}
                        RETURNING id_pesanan
                    ");
                    $stmt->execute($ids);
                    $terkena = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($terkena as $pid) {
                        catat_log_admin($pdo, $arsipkan ? "Arsipkan" : "Pulihkan", "Pesanan", (int)$pid);
                    }

                    set_flash("sukses", count($terkena) . " pesanan " . ($arsipkan ? "diarsipkan." : "dipulihkan."));
                } catch (PDOException $e) {
                    error_log("Arsip pesanan gagal: " . $e->getMessage());
                    set_flash("gagal", "Terjadi kesalahan database. Coba lagi.");
                }
            }
            redirect("pesanan.php" . ($arsipkan ? "" : "?arsip=1"));
        }
    }
}

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
    WHERE p.diarsipkan = " . ($tampilArsip ? "TRUE" : "FALSE") . "
";
$params = [];
if ($statusFilter !== "") {
    $sql .= " AND p.status_pesanan = :status";
    $params[":status"] = $statusFilter;
}
$sql .= " ORDER BY p.id_pesanan DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pesanan = $stmt->fetchAll();

$jumlahArsip = (int)$pdo->query("SELECT COUNT(*) FROM pesanan WHERE diarsipkan = TRUE")->fetchColumn();

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
    "Siap Diambil"         => ["ship",    "ic-truck"],
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

/** Alamat opsi filter. Status dikodekan agar spasi ("Menunggu Pembayaran") aman di URL; $arsip mempertahankan mode arsip. */
function url_filter(string $status = "", bool $arsip = false): string
{
    $q = [];
    if ($status !== "") {
        $q["status"] = $status;
    }
    if ($arsip) {
        $q["arsip"] = 1;
    }
    return "/admin/pesanan.php" . ($q ? "?" . http_build_query($q) : "");
}

$opsiFilter = ["" => "Semua"];
foreach ($daftarStatus as $s) {
    $opsiFilter[$s] = $s;
}

// Label tombol filter = status yang sedang aktif ("Semua" bila tanpa filter).
$labelFilter = $statusFilter !== "" ? $statusFilter : "Semua";
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
    <link rel="stylesheet" href="/assets/css/pesanan-tools.css?v=<?= @filemtime(__DIR__ . "/../assets/css/pesanan-tools.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-pesanan.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-pesanan.css") ?>">
    <!-- Tanpa JS dropdown tidak bisa dibuka; tampilkan menunya terus supaya filter tetap terjangkau -->
    <noscript><style>.pt-menu { position: static; opacity: 1; visibility: visible; transform: none; margin-top: .8rem; }</style></noscript>
    <script src="/assets/js/pesanan-tools.js?v=<?= @filemtime(__DIR__ . "/../assets/js/pesanan-tools.js") ?>" defer></script>
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
                    <h1><?= $tampilArsip ? "Arsip Pesanan" : "Kelola Pesanan Merchandise" ?></h1>
                    <p><?= $tampilArsip ? "Pesanan yang sudah dihapus dari daftar aktif; bisa dipulihkan kapan saja." : "Kelola pesanan merchandise JTI" ?></p>
                </div>
            </div>
        </header>

        <section class="po-card" aria-label="Daftar pesanan">

            <?php if ($flash): ?>
                <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="<?= ($flash["type"] ?? "") === "sukses" ? "status" : "alert" ?>">
                    <?= e($flash["msg"]) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <p>
                <?php if ($tampilArsip): ?>
                    <a href="<?= e(url_filter($statusFilter)) ?>">&larr; Kembali ke daftar aktif</a>
                <?php else: ?>
                    <a href="<?= e(url_filter($statusFilter, true)) ?>">Lihat Arsip (<?= $jumlahArsip ?>)</a>
                <?php endif; ?>
            </p>

            <div class="pt" data-pt data-pt-mode="server" data-pt-label="<?= e($statusFilter) ?>">

                <!-- Toolbar: pencarian nama pembeli (kiri) + tombol filter "Semua" dengan dropdown status (kanan).
                     Tiap opsi = tautan ?status=..., jadi data tetap difilter oleh query backend di atas. -->
                <div class="pt-toolbar">
                    <div class="pt-search">
                        <label class="pt-search__label" for="pt-cari">Nama Pembeli</label>
                        <input class="pt-search__input" type="search" id="pt-cari" name="cari" placeholder="Masukkan nama pembeli.." autocomplete="off" data-pt-search>
                    </div>

                    <div class="pt-filter" data-pt-dropdown>
                        <button type="button" class="pt-filter__btn" id="pt-filter-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="pt-filter-menu" data-pt-trigger>
                            <img src="/assets/img/icon-filter.png" alt="" width="48" height="48">
                            <span data-pt-filter-label><?= e($labelFilter) ?></span>
                            <svg class="pt-filter__caret" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 6 5 5 5-5"/></svg>
                        </button>
                        <div class="pt-menu" id="pt-filter-menu" role="menu" aria-labelledby="pt-filter-btn" data-pt-menu>
                            <?php foreach ($opsiFilter as $nilai => $label): ?>
                                <?php $aktif = ((string)$nilai === $statusFilter); ?>
                                <a class="pt-menu__item" role="menuitemradio" aria-checked="<?= $aktif ? "true" : "false" ?>" href="<?= e(url_filter((string)$nilai, $tampilArsip)) ?>"><?= e($label) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php if (!$pesanan): ?>

                    <div class="dash-empty">
                        <p>
                            <?php if ($tampilArsip): ?>
                                Belum ada pesanan yang diarsipkan<?= $statusFilter !== "" ? " dengan status tersebut." : "." ?>
                            <?php else: ?>
                                Tidak ada pesanan<?= $statusFilter !== "" ? " dengan status tersebut." : "." ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($statusFilter !== ""): ?>
                            <p><a href="<?= e(url_filter("", $tampilArsip)) ?>">Tampilkan semua pesanan</a></p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <!-- Pilih Semua (jumlah baris yang tampil) + Hapus/Pulihkan Yang Dipilih (kirim via #form-pt-bulk, lihat bawah) -->
                    <div class="pt-bar">
                        <label class="pt-select">
                            <input class="pt-check" type="checkbox" data-pt-all>
                            <span>Pilih Semua (<span data-pt-count><?= count($pesanan) ?></span>)</span>
                        </label>
                        <button type="submit" form="form-pt-bulk" class="pt-bulk" data-pt-bulk disabled>
                            <img src="/assets/img/icon-trash.png" alt="" width="40" height="40">
                            <span><?= $tampilArsip ? "Pulihkan Yang Dipilih" : "Hapus Yang Dipilih" ?></span>
                        </button>
                    </div>
                    <p class="pt-note" data-pt-note role="status" hidden></p>

                    <p class="po-sr" data-pt-info role="status"></p>

                    <div class="po-table-wrap" data-pt-wrap>
                        <table class="po-table pt-table" data-pt-table>
                            <caption class="po-sr">
                                Daftar pesanan<?= $statusFilter !== "" ? " dengan status " . e($statusFilter) : "" ?>
                            </caption>
                            <colgroup><col><col><col><col><col><col><col><col></colgroup>
                            <thead>
                                <tr>
                                    <th scope="col" class="pt-th-check"><span class="pt-sr">Pilih</span></th>
                                    <th scope="col">No</th>
                                    <th scope="col" class="is-left">Tanggal</th>
                                    <th scope="col" class="is-left">Pembeli</th>
                                    <th scope="col">Item</th>
                                    <th scope="col">Status Pesanan</th>
                                    <th scope="col">Pembayaran</th>
                                    <th scope="col" class="is-right">Harga</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pesanan as $i => $p): ?>
                                    <?php $urlDetail = "/admin/pesanan-detail.php?id=" . (int)$p["id_pesanan"]; ?>
                                    <tr style="--i:<?= (int)$i ?>" data-href="<?= e($urlDetail) ?>" data-id="<?= (int)$p["id_pesanan"] ?>" data-search="<?= e(($p["nama_mhs"] ?? $p["username"]) . " " . ($p["nim"] ?? "")) ?>">
                                        <td class="pt-td-check">
                                            <input class="pt-check" type="checkbox" name="ids[]" form="form-pt-bulk" value="<?= (int)$p["id_pesanan"] ?>" data-pt-row-check aria-label="Pilih pesanan nomor <?= (int)$p["id_pesanan"] ?>">
                                        </td>
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
                                        <td data-label="Harga" class="is-right"><span class="po-total"><?= e(format_rupiah((float)$p["total_harga"])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Muncul (diatur JS) bila pencarian nama pembeli tidak menemukan pesanan -->
                    <div class="dash-empty" data-pt-empty hidden>
                        <p data-pt-empty-text>Tidak ada pesanan.</p>
                        <p><button type="button" class="po-link" data-pt-reset>Hapus pencarian</button></p>
                    </div>

                <?php endif; ?>

                <!-- Checkbox baris memakai atribut form="form-pt-bulk"; "Hapus"/"Pulihkan Yang Dipilih" mengirim form ini. -->
                <?php
                $konfirmBulk = json_encode(
                    $tampilArsip ? "Pulihkan pesanan yang dipilih?" : "Hapus (arsipkan) pesanan yang dipilih? Data tetap tersimpan dan bisa dipulihkan lewat Arsip.",
                    JSON_UNESCAPED_UNICODE
                );
                ?>
                <form id="form-pt-bulk" method="POST" hidden onsubmit="return confirm(<?= e($konfirmBulk) ?>)">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $tampilArsip ? "pulihkan_terpilih" : "arsipkan_terpilih" ?>">
                </form>

            </div>

        </section>
    </div>
</main>

</body>
</html>
