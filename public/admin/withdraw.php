<?php

/**
 * Admin — permintaan withdraw dompet mahasiswa.
 *
 * Saldo sudah ditahan saat user mengajukan (fn_withdraw_ajukan). Admin mentransfer
 * ke tujuan yang diisi user, lalu:
 *   - Selesai : transfer sudah dilakukan (catatan opsional, terlihat oleh user)
 *   - Tolak   : permintaan ditolak, saldo dikembalikan ke dompet (alasan wajib)
 * Logika perubahan ada di function database (database/withdraw.sql).
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

$filter = $_GET["status"] ?? "Diproses";
if (!in_array($filter, ["Diproses", "Selesai", "Ditolak", "Semua"], true)) {
    $filter = "Diproses";
}
$halaman = max(1, (int)($_GET["hal"] ?? 1));

$baris = [];
$total = 0;
$jumlahMenunggu = 0;

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

function url_wd(string $status, int $hal): string
{
    return "/admin/withdraw.php?" . http_build_query(array_filter(["status" => $status !== "Diproses" ? $status : null, "hal" => $hal > 1 ? $hal : null]));
}
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
    <link rel="stylesheet" href="/assets/css/admin-kategori.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-kategori.css") ?>">
</head>
<body class="page-dashboard">
<?php
$adminActive     = "withdraw";
$adminNavRingkas = true;
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-kategori">
    <div class="kt">

        <header class="kt-head">
            <nav class="kt-crumb" aria-label="Breadcrumb">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/withdraw.php" aria-current="page">Withdraw Dompet</a></span>
            </nav>
            <div class="kt-title">
                <div>
                    <h1>Withdraw Dompet</h1>
                    <p>Transfer ke tujuan yang diisi mahasiswa, lalu tandai Selesai. Tolak akan mengembalikan saldo.</p>
                </div>
            </div>
        </header>

        <section class="kt-card" aria-label="Permintaan withdraw">

            <?php if ($flash): ?>
                <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="status">
                    <?= e($flash["msg"]) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <p>
                Tampilkan:
                <?php foreach (["Diproses" => "Diproses (" . $jumlahMenunggu . ")", "Selesai" => "Selesai", "Ditolak" => "Ditolak", "Semua" => "Semua"] as $nilai => $teks): ?>
                    <?php if ($nilai === $filter): ?>
                        <b><?= e($teks) ?></b>
                    <?php else: ?>
                        <a href="<?= e(url_wd($nilai, 1)) ?>"><?= e($teks) ?></a>
                    <?php endif; ?>
                    &nbsp;
                <?php endforeach; ?>
            </p>

            <?php if (!$baris): ?>

                <div class="dash-empty">
                    <p>Tidak ada permintaan withdraw<?= $filter === "Diproses" ? " yang menunggu" : "" ?>.</p>
                </div>

            <?php else: ?>

                <div class="kt-table-wrap">
                    <table class="kt-table">
                        <caption class="kt-sr">Permintaan withdraw dompet</caption>
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Diajukan</th>
                                <th scope="col">Mahasiswa</th>
                                <th scope="col">Jumlah</th>
                                <th scope="col">Tujuan transfer</th>
                                <th scope="col">Status</th>
                                <th scope="col">Aksi / catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($baris as $w): ?>
                                <tr>
                                    <td data-label="ID">#<?= (int)$w["id_withdraw"] ?></td>
                                    <td data-label="Diajukan"><?= e(substr((string)$w["created_at"], 0, 16)) ?></td>
                                    <td data-label="Mahasiswa">
                                        <?= e($w["nama"] ?? $w["username"]) ?><br>
                                        <small><?= e($w["nim"] ?? "-") ?> · <?= e($w["username"]) ?></small>
                                    </td>
                                    <td data-label="Jumlah"><b>Rp <?= number_format((float)$w["jumlah"], 0, ",", ".") ?></b></td>
                                    <td data-label="Tujuan transfer"><?= e($w["tujuan"]) ?></td>
                                    <td data-label="Status">
                                        <b><?= e($w["status"]) ?></b>
                                        <?php if ($w["diproses_pada"]): ?>
                                            <br><small><?= e(substr((string)$w["diproses_pada"], 0, 16)) ?><?= $w["admin_username"] ? " · " . e($w["admin_username"]) : "" ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Aksi / catatan">
                                        <?php if ($w["status"] === "Diproses"): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="id_withdraw" value="<?= (int)$w["id_withdraw"] ?>">
                                                <input type="text" name="catatan" maxlength="300" placeholder="Catatan / alasan tolak">
                                                <button type="submit" name="action" value="selesai"
                                                        onclick="return confirm('Tandai withdraw #<?= (int)$w["id_withdraw"] ?> SELESAI? Pastikan transfer sudah dilakukan.')">Selesai</button>
                                                <button type="submit" name="action" value="tolak"
                                                        onclick="return confirm('Tolak withdraw #<?= (int)$w["id_withdraw"] ?>? Saldo dikembalikan ke dompet mahasiswa.')">Tolak</button>
                                            </form>
                                        <?php else: ?>
                                            <?= e($w["catatan_admin"] ?? "") ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($jumlahHalaman > 1): ?>
                    <p>
                        <?php if ($halaman > 1): ?>
                            <a href="<?= e(url_wd($filter, $halaman - 1)) ?>">&larr; Lebih baru</a>
                        <?php endif; ?>
                        Halaman <?= $halaman ?> / <?= $jumlahHalaman ?>
                        <?php if ($halaman < $jumlahHalaman): ?>
                            <a href="<?= e(url_wd($filter, $halaman + 1)) ?>">Lebih lama &rarr;</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

            <?php endif; ?>

        </section>
    </div>
</main>

</body>
</html>
