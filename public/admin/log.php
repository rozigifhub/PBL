<?php

/**
 * Admin — log aktivitas admin (siapa menambah / mengubah / menghapus apa, dan kapan).
 * Data ditulis oleh catat_log_admin() (config/functions.php); halaman ini hanya membaca.
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

function url_log(string $entitas, int $hal): string
{
    return "/admin/log.php?" . http_build_query(array_filter(["entitas" => $entitas, "hal" => $hal > 1 ? $hal : null]));
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
    <link rel="stylesheet" href="/assets/css/admin-kategori.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-kategori.css") ?>">
</head>
<body class="page-dashboard">
<?php
$adminActive     = "log";
$adminNavRingkas = true;
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-kategori">
    <div class="kt">

        <header class="kt-head">
            <nav class="kt-crumb" aria-label="Breadcrumb">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/log.php" aria-current="page">Log Aktivitas</a></span>
            </nav>
            <div class="kt-title">
                <div>
                    <h1>Log Aktivitas Admin</h1>
                    <p>Riwayat siapa yang menambah, mengubah, dan menghapus data</p>
                </div>
            </div>
        </header>

        <section class="kt-card" aria-label="Log aktivitas">

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <p>
                Tampilkan:
                <?php foreach (["" => "Semua", "Produk" => "Produk", "Kategori" => "Kategori", "Pesanan" => "Pesanan", "Withdraw" => "Withdraw"] as $nilai => $teks): ?>
                    <?php if ($nilai === $filterEntitas): ?>
                        <b><?= e($teks) ?></b>
                    <?php else: ?>
                        <a href="<?= e(url_log($nilai, 1)) ?>"><?= e($teks) ?></a>
                    <?php endif; ?>
                    &nbsp;
                <?php endforeach; ?>
                <small>(<?= $total ?> catatan)</small>
            </p>

            <?php if (!$baris): ?>

                <div class="dash-empty">
                    <p>Belum ada aktivitas tercatat.</p>
                </div>

            <?php else: ?>

                <div class="kt-table-wrap">
                    <table class="kt-table">
                        <caption class="kt-sr">Log aktivitas admin</caption>
                        <thead>
                            <tr>
                                <th scope="col">Waktu</th>
                                <th scope="col">Admin</th>
                                <th scope="col">Aksi</th>
                                <th scope="col">Objek</th>
                                <th scope="col">Detail</th>
                                <th scope="col">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($baris as $b): ?>
                                <?php $tautan = tautan_entitas((string)$b["entitas"], $b["id_entitas"] !== null ? (int)$b["id_entitas"] : null); ?>
                                <tr>
                                    <td data-label="Waktu"><?= e(substr((string)$b["created_at"], 0, 19)) ?></td>
                                    <td data-label="Admin"><?= e($b["username"]) ?></td>
                                    <td data-label="Aksi"><b><?= e($b["aksi"]) ?></b></td>
                                    <td data-label="Objek">
                                        <?= e($b["entitas"]) ?>
                                        <?php if ($tautan !== "" && $b["aksi"] !== "Hapus"): ?>
                                            <a href="<?= e($tautan) ?>">#<?= (int)$b["id_entitas"] ?></a>
                                        <?php elseif ($b["id_entitas"] !== null): ?>
                                            #<?= (int)$b["id_entitas"] ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Detail"><?= e($b["detail"]) ?></td>
                                    <td data-label="IP"><?= e($b["ip"] ?? "") ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($jumlahHalaman > 1): ?>
                    <p>
                        <?php if ($halaman > 1): ?>
                            <a href="<?= e(url_log($filterEntitas, $halaman - 1)) ?>">&larr; Lebih baru</a>
                        <?php endif; ?>
                        Halaman <?= $halaman ?> / <?= $jumlahHalaman ?>
                        <?php if ($halaman < $jumlahHalaman): ?>
                            <a href="<?= e(url_log($filterEntitas, $halaman + 1)) ?>">Lebih lama &rarr;</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

            <?php endif; ?>

        </section>
    </div>
</main>

</body>
</html>
