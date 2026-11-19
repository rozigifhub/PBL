<?php

/**
 * Admin — permintaan withdraw dompet mahasiswa.
 *
 * Saldo sudah ditahan saat user mengajukan (fn_withdraw_ajukan). Admin mentransfer
 * ke tujuan yang diisi user, lalu:
 *   - Selesai : transfer sudah dilakukan (catatan opsional, terlihat oleh user)
 *   - Tolak   : permintaan ditolak, saldo dikembalikan ke dompet (alasan wajib)
 * Logika perubahan ada di function database (database/withdraw.sql).
 *
 * Tampilan mengikuti desain UI/UX (breadcrumb + ikon dompet, tombol filter "Semua" + dropdown status,
 * tabel dalam kartu krem). Auth, POST handler, dan query tetap dari tim backend.
 * Filter status tetap dijalankan query di bawah (opsi dropdown = tautan ?status=...);
 * JS dropdown dipakai bersama dengan Kelola Pesanan (assets/js/pesanan-tools.js).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

const WD_PER_HALAMAN = 50;

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action  = $_POST["action"] ?? "";
        $idWd    = (int)($_POST["id_withdraw"] ?? 0);
        $catatan = trim((string)($_POST["catatan"] ?? ""));

        if (!in_array($action, ["selesai", "tolak"], true) || $idWd <= 0) {
            $error = "Permintaan tidak valid.";
        } elseif (mb_strlen($catatan) > 300) {
            $error = "Catatan maksimal 300 karakter.";
        } elseif ($action === "tolak" && $catatan === "") {
            $error = "Alasan penolakan wajib diisi.";
        } else {
            try {
                $fungsi = $action === "selesai" ? "fn_withdraw_selesai" : "fn_withdraw_tolak";
                $stmt = $pdo->prepare("SELECT {$fungsi}(:id, :admin, :catatan)");
                $stmt->execute([":id" => $idWd, ":admin" => (int)$_SESSION["id_akun"], ":catatan" => $catatan]);

                if ($stmt->fetchColumn()) {
                    catat_log_admin($pdo, $action === "selesai" ? "Selesai" : "Tolak", "Withdraw", $idWd,
                        $catatan !== "" ? $catatan : "(tanpa catatan)");
                    set_flash("sukses", $action === "selesai"
                        ? "Withdraw #{$idWd} ditandai selesai."
                        : "Withdraw #{$idWd} ditolak, saldo dikembalikan ke dompet.");
                    redirect("withdraw.php");
                }
                $error = "Permintaan #{$idWd} tidak ditemukan atau sudah diproses.";
            } catch (PDOException $e) {
                error_log("Admin withdraw gagal: " . $e->getMessage());
                $error = "Terjadi kesalahan database. Coba lagi.";
            }
        }
    }
}

// Tampilan awal = "Semua" (sesuai desain). "Diproses" tetap valid bila dibuka lewat URL ?status=Diproses.
$filter = $_GET["status"] ?? "Semua";
if (!in_array($filter, ["Diproses", "Selesai", "Ditolak", "Semua"], true)) {
    $filter = "Semua";
}
$halaman = max(1, (int)($_GET["hal"] ?? 1));

$baris = [];
$total = 0;
$jumlahMenunggu = 0;   // jumlah permintaan berstatus Diproses (belum dipakai di desain baru, dibiarkan dari backend)

try {
    $where  = $filter !== "Semua" ? "WHERE w.status = :status" : "";
    $params = $filter !== "Semua" ? [":status" => $filter] : [];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdraw_dompet w {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT w.id_withdraw, w.jumlah, w.tujuan, w.status, w.catatan_admin, w.created_at, w.diproses_pada,
               a.username, m.nim, m.nama, ad.username AS admin_username
        FROM withdraw_dompet w
        JOIN akun_login a ON a.id_akun = w.id_akun
        LEFT JOIN mahasiswa m ON m.id_akun = w.id_akun
        LEFT JOIN akun_login ad ON ad.id_akun = w.id_admin
        {$where}
        ORDER BY w.id_withdraw DESC
        LIMIT " . WD_PER_HALAMAN . " OFFSET " . (($halaman - 1) * WD_PER_HALAMAN)
    );
    $stmt->execute($params);
    $baris = $stmt->fetchAll();

    $jumlahMenunggu = (int)$pdo->query("SELECT COUNT(*) FROM withdraw_dompet WHERE status = 'Diproses'")->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin withdraw gagal dibaca: " . $e->getMessage());
    $error = $error !== "" ? $error : "Data withdraw belum bisa dibaca. Pastikan database/withdraw.sql sudah dijalankan.";
}

$jumlahHalaman = max(1, (int)ceil($total / WD_PER_HALAMAN));
$flash = take_flash();

/** Alamat halaman + filter. "Semua" = tanpa parameter status (tampilan awal). */
function url_wd(string $status, int $hal): string
{
    $query = http_build_query(array_filter(["status" => $status !== "Semua" ? $status : null, "hal" => $hal > 1 ? $hal : null]));
    return "/admin/withdraw.php" . ($query !== "" ? "?" . $query : "");
}

// Opsi dropdown filter (sama polanya dengan Kelola Pesanan: tiap opsi = tautan ?status=...).
$opsiFilter = ["Semua", "Selesai", "Ditolak"];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Withdraw Dompet - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/pesanan-tools.css?v=<?= @filemtime(__DIR__ . "/../assets/css/pesanan-tools.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-withdraw.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-withdraw.css") ?>">
    <!-- Tanpa JS dropdown tidak bisa dibuka; tampilkan menunya terus supaya filter tetap terjangkau -->
    <noscript><style>.pt-menu { position: static; opacity: 1; visibility: visible; transform: none; margin-top: .8rem; }</style></noscript>
    <script src="/assets/js/pesanan-tools.js?v=<?= @filemtime(__DIR__ . "/../assets/js/pesanan-tools.js") ?>" defer></script>
    <script src="/assets/js/admin-withdraw.js?v=<?= @filemtime(__DIR__ . "/../assets/js/admin-withdraw.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$adminActive     = "withdraw";
$adminNavRingkas = true;
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-withdraw">
    <div class="wd">

        <!-- Kepala halaman: breadcrumb + ikon dompet + judul -->
        <header class="wd-head">
            <nav class="wd-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/withdraw-ic-home.png" alt="" width="172" height="172">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/withdraw.php" aria-current="page">Withdraw Dompet</a></span>
            </nav>
            <div class="wd-title">
                <span class="wd-title__icon" aria-hidden="true"><img src="/assets/img/withdraw-ic-wallet.png" alt="" width="360" height="360"></span>
                <div>
                    <h1>Withdraw Dompet</h1>
                    <p>Transfer ke tujuan yang diisi mahasiswa, lalu tandai Selesai. Tolak akan mengembalikan saldo.</p>
                </div>
            </div>
        </header>

        <section class="wd-card" aria-label="Permintaan withdraw">

            <?php if ($flash): ?>
                <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="status">
                    <?= e($flash["msg"]) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="pt" data-pt data-pt-mode="server" data-pt-label="<?= $filter !== "Semua" ? e($filter) : "" ?>">

                <!-- Tombol filter (label = status yang sedang aktif) + dropdown.
                     Tiap opsi = tautan ?status=..., jadi data tetap difilter oleh query backend di atas.
                     Cara kerjanya sama dengan filter "Semua" di halaman Kelola Pesanan. -->
                <div class="wd-toolbar">
                    <div class="pt-filter" data-pt-dropdown>
                        <button type="button" class="pt-filter__btn" id="pt-filter-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="pt-filter-menu" data-pt-trigger>
                            <img src="/assets/img/icon-filter.png" alt="" width="48" height="48">
                            <span data-pt-filter-label><?= e($filter) ?></span>
                            <svg class="pt-filter__caret" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 6 5 5 5-5"/></svg>
                        </button>
                        <div class="pt-menu" id="pt-filter-menu" role="menu" aria-labelledby="pt-filter-btn" data-pt-menu>
                            <?php foreach ($opsiFilter as $nilai): ?>
                                <a class="pt-menu__item" role="menuitemradio" aria-checked="<?= $nilai === $filter ? "true" : "false" ?>" href="<?= e(url_wd($nilai, 1)) ?>"><?= e($nilai) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php if (!$baris): ?>

                    <div class="dash-empty">
                        <p>Tidak ada permintaan withdraw<?= $filter !== "Semua" ? " dengan status " . e($filter) : "" ?>.</p>
                        <?php if ($filter !== "Semua"): ?>
                            <p><a href="<?= e(url_wd("Semua", 1)) ?>">Tampilkan semua permintaan</a></p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="wd-table-wrap">
                        <table class="wd-table">
                            <caption class="wd-sr">Permintaan withdraw dompet</caption>
                            <colgroup><col><col><col><col><col><col></colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Tanggal</th>
                                    <th scope="col">Nama</th>
                                    <th scope="col">Jumlah</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Aksi/Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- LOOPING PERMINTAAN WITHDRAW MULAI DARI SINI ($baris dari query backend) -->
                                <?php foreach ($baris as $i => $w): ?>
                                    <tr style="--i:<?= (int)$i ?>">
                                        <td data-label="ID" class="is-num"><?= (int)$w["id_withdraw"] ?></td>
                                        <td data-label="Tanggal" class="is-num"><?= e(substr((string)$w["created_at"], 0, 16)) ?></td>
                                        <td data-label="Nama">
                                            <div class="wd-who">
                                                <span class="wd-name"><?= e($w["nama"] ?? $w["username"]) ?></span>
                                                <small class="wd-sub"><?= e($w["nim"] ?? "-") ?> · <?= e($w["username"]) ?></small>
                                                <!-- Tujuan transfer tidak ada di desain, tapi admin butuh untuk mentransfer: ditampilkan kecil di bawah nama -->
                                                <small class="wd-dest">Transfer ke: <b><?= e($w["tujuan"]) ?></b></small>
                                            </div>
                                        </td>
                                        <td data-label="Jumlah" class="is-num">Rp <?= number_format((float)$w["jumlah"], 0, ",", ".") ?></td>
                                        <td data-label="Status">
                                            <div class="wd-stat">
                                                <span class="wd-status"><?= e($w["status"]) ?></span>
                                                <?php if ($w["diproses_pada"]): ?>
                                                    <small class="wd-sub"><?= e(substr((string)$w["diproses_pada"], 0, 16)) ?><?= $w["admin_username"] ? " · " . e($w["admin_username"]) : "" ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Aksi/Catatan">
                                            <?php if ($w["status"] === "Diproses"): ?>
                                                <!-- FORM AKSI (name input, action, onclick confirm = backend, jangan diubah) -->
                                                <form method="POST" class="wd-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="id_withdraw" value="<?= (int)$w["id_withdraw"] ?>">
                                                    <input type="text" name="catatan" maxlength="300" class="wd-note" placeholder="Catatan / alasan tolak">
                                                    <div class="wd-form__btns">
                                                        <button type="submit" name="action" value="selesai" class="wd-btn wd-btn--ok"
                                                                onclick="return confirm('Tandai withdraw #<?= (int)$w["id_withdraw"] ?> SELESAI? Pastikan transfer sudah dilakukan.')">Selesai</button>
                                                        <button type="submit" name="action" value="tolak" class="wd-btn wd-btn--no"
                                                                onclick="return confirm('Tolak withdraw #<?= (int)$w["id_withdraw"] ?>? Saldo dikembalikan ke dompet mahasiswa.')">Tolak</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <?php $catatanAdmin = trim((string)($w["catatan_admin"] ?? "")); ?>
                                                <span class="wd-catatan"><?= $catatanAdmin !== "" ? e($catatanAdmin) : "-" ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- LOOPING PERMINTAAN WITHDRAW SELESAI DI SINI -->
                            </tbody>
                        </table>
                    </div>

                    <?php if ($jumlahHalaman > 1): ?>
                        <nav class="wd-pager" aria-label="Halaman">
                            <?php if ($halaman > 1): ?>
                                <a href="<?= e(url_wd($filter, $halaman - 1)) ?>">&larr; Lebih baru</a>
                            <?php endif; ?>
                            <span>Halaman <?= $halaman ?> / <?= $jumlahHalaman ?></span>
                            <?php if ($halaman < $jumlahHalaman): ?>
                                <a href="<?= e(url_wd($filter, $halaman + 1)) ?>">Lebih lama &rarr;</a>
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
