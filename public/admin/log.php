<?php

/**
 * Admin — log aktivitas admin (siapa menambah / mengubah / menghapus apa, dan kapan).
 * Data ditulis oleh catat_log_admin() (config/functions.php); halaman ini hanya membaca.
 *
 * Tampilan disamakan dengan Withdraw Dompet (breadcrumb + ikon, tombol filter "Semua" + dropdown, tabel dalam kartu krem).
 * Query dan filter tetap dari backend; opsi dropdown = tautan ?entitas=..., JS dropdown dipakai bersama
 * dengan Kelola Pesanan (assets/js/pesanan-tools.js).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

const LOG_PER_HALAMAN = 50;

$filterEntitas = $_GET["entitas"] ?? "";
if (!in_array($filterEntitas, ["Produk", "Kategori", "Pesanan", "Withdraw"], true)) {
    $filterEntitas = "";
}
$halaman = max(1, (int)($_GET["hal"] ?? 1));

$error = "";
$baris = [];
$total = 0;

try {
    $where  = $filterEntitas !== "" ? "WHERE entitas = :entitas" : "";
    $params = $filterEntitas !== "" ? [":entitas" => $filterEntitas] : [];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM log_admin {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id_log, created_at, username, aksi, entitas, id_entitas, detail, ip
        FROM log_admin
        {$where}
        ORDER BY id_log DESC
        LIMIT " . LOG_PER_HALAMAN . " OFFSET " . (($halaman - 1) * LOG_PER_HALAMAN)
    );
    $stmt->execute($params);
    $baris = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Admin log gagal: " . $e->getMessage());
    $error = "Log belum bisa dibaca. Pastikan tabel log_admin sudah dibuat (database/log_admin.sql).";
}

$jumlahHalaman = max(1, (int)ceil($total / LOG_PER_HALAMAN));

/** Alamat halaman + filter. Entitas kosong ("Semua") = tanpa parameter (tampilan awal). */
function url_log(string $entitas, int $hal): string
{
    $query = http_build_query(array_filter(["entitas" => $entitas, "hal" => $hal > 1 ? $hal : null]));
    return "/admin/log.php" . ($query !== "" ? "?" . $query : "");
}

function tautan_entitas(string $entitas, ?int $id): string
{
    if ($id === null) {
        return "";
    }
    return match ($entitas) {
        "Pesanan"  => "/admin/pesanan-detail.php?id=" . $id,
        "Produk"   => "/admin/merchandise.php?edit=" . $id,
        "Kategori" => "/admin/kategori.php?edit=" . $id,
        "Withdraw" => "/admin/withdraw.php",
        default    => "",
    };
}

// Opsi dropdown filter: nilai ?entitas= => label ("" = Semua).
$opsiFilter = ["" => "Semua", "Produk" => "Produk", "Kategori" => "Kategori", "Pesanan" => "Pesanan", "Withdraw" => "Withdraw"];
$labelFilter = $opsiFilter[$filterEntitas];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log Aktivitas - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/pesanan-tools.css?v=<?= @filemtime(__DIR__ . "/../assets/css/pesanan-tools.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-log.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-log.css") ?>">
    <!-- Tanpa JS dropdown tidak bisa dibuka; tampilkan menunya terus supaya filter tetap terjangkau -->
    <noscript><style>.pt-menu { position: static; opacity: 1; visibility: visible; transform: none; margin-top: .8rem; }</style></noscript>
    <script src="/assets/js/pesanan-tools.js?v=<?= @filemtime(__DIR__ . "/../assets/js/pesanan-tools.js") ?>" defer></script>
    <script src="/assets/js/admin-log.js?v=<?= @filemtime(__DIR__ . "/../assets/js/admin-log.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$adminActive     = "log";
$adminNavRingkas = true;
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-log">
    <div class="lg">

        <!-- Kepala halaman: breadcrumb + ikon + judul -->
        <header class="lg-head">
            <nav class="lg-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/withdraw-ic-home.png" alt="" width="172" height="172">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/log.php" aria-current="page">Log Aktivitas</a></span>
            </nav>
            <div class="lg-title">
                <span class="lg-title__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                        <path d="M13 6h22a3 3 0 0 1 3 3v30a3 3 0 0 1-3 3H13a3 3 0 0 1-3-3V9a3 3 0 0 1 3-3z"/>
                        <path d="M17 16h14M17 24h14M17 32h8"/>
                    </svg>
                </span>
                <div>
                    <h1>Log Aktivitas Admin</h1>
                    <p>Riwayat siapa yang menambah, mengubah, dan menghapus data</p>
                </div>
            </div>
        </header>

        <section class="lg-card" aria-label="Log aktivitas">

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="pt" data-pt data-pt-mode="server" data-pt-label="<?= e($filterEntitas) ?>">

                <!-- Tombol filter (label = objek yang sedang aktif) + dropdown.
                     Tiap opsi = tautan ?entitas=..., jadi data tetap difilter oleh query backend di atas.
                     Cara kerjanya sama dengan filter "Semua" di Kelola Pesanan dan Withdraw Dompet. -->
                <div class="lg-toolbar">
                    <div class="pt-filter" data-pt-dropdown>
                        <button type="button" class="pt-filter__btn" id="pt-filter-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="pt-filter-menu" data-pt-trigger>
                            <img src="/assets/img/icon-filter.png" alt="" width="48" height="48">
                            <span data-pt-filter-label><?= e($labelFilter) ?></span>
                            <svg class="pt-filter__caret" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 6 5 5 5-5"/></svg>
                        </button>
                        <div class="pt-menu" id="pt-filter-menu" role="menu" aria-labelledby="pt-filter-btn" data-pt-menu>
                            <?php foreach ($opsiFilter as $nilai => $teks): ?>
                                <a class="pt-menu__item" role="menuitemradio" aria-checked="<?= (string)$nilai === $filterEntitas ? "true" : "false" ?>" href="<?= e(url_log((string)$nilai, 1)) ?>"><?= e($teks) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <span class="lg-count" role="status"><?= (int)$total ?> catatan</span>
                </div>

                <?php if (!$baris): ?>

                    <div class="dash-empty">
                        <p>Belum ada aktivitas tercatat<?= $filterEntitas !== "" ? " untuk " . e($filterEntitas) : "" ?>.</p>
                        <?php if ($filterEntitas !== ""): ?>
                            <p><a href="<?= e(url_log("", 1)) ?>">Tampilkan semua aktivitas</a></p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="lg-table-wrap">
                        <table class="lg-table">
                            <caption class="lg-sr">Log aktivitas admin</caption>
                            <colgroup><col><col><col><col><col><col></colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">Waktu</th>
                                    <th scope="col">Admin</th>
                                    <th scope="col">Aksi</th>
                                    <th scope="col">Objek</th>
                                    <th scope="col" class="is-left">Detail</th>
                                    <th scope="col">IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($baris as $i => $b): ?>
                                    <?php $tautan = tautan_entitas((string)$b["entitas"], $b["id_entitas"] !== null ? (int)$b["id_entitas"] : null); ?>
                                    <tr style="--i:<?= (int)$i ?>">
                                        <td data-label="Waktu" class="is-num"><?= e(substr((string)$b["created_at"], 0, 19)) ?></td>
                                        <td data-label="Admin"><?= e($b["username"]) ?></td>
                                        <td data-label="Aksi"><b><?= e($b["aksi"]) ?></b></td>
                                        <td data-label="Objek">
                                            <span class="lg-obj">
                                                <?= e($b["entitas"]) ?>
                                                <?php if ($tautan !== "" && $b["aksi"] !== "Hapus"): ?>
                                                    <a class="lg-link" href="<?= e($tautan) ?>">#<?= (int)$b["id_entitas"] ?></a>
                                                <?php elseif ($b["id_entitas"] !== null): ?>
                                                    #<?= (int)$b["id_entitas"] ?>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td data-label="Detail" class="is-left"><?= e($b["detail"]) ?></td>
                                        <td data-label="IP" class="is-num"><?= e($b["ip"] ?? "") ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($jumlahHalaman > 1): ?>
                        <nav class="lg-pager" aria-label="Halaman">
                            <?php if ($halaman > 1): ?>
                                <a href="<?= e(url_log($filterEntitas, $halaman - 1)) ?>">&larr; Lebih baru</a>
                            <?php endif; ?>
                            <span>Halaman <?= $halaman ?> / <?= $jumlahHalaman ?></span>
                            <?php if ($halaman < $jumlahHalaman): ?>
                                <a href="<?= e(url_log($filterEntitas, $halaman + 1)) ?>">Lebih lama &rarr;</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </section>
    </div>
</main>

</body>
</html>
