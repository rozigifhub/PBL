<?php

/**
 * Detail pesanan milik mahasiswa (dipakai juga sebagai halaman
 * konfirmasi setelah checkout: pesanan.php?id=X&baru=1).
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_mahasiswa();

$id   = (int)($_GET["id"] ?? 0);
$baru = isset($_GET["baru"]);

// Callback Midtrans mengirim order_id (format: PBL-00001-<timestamp>), bukan id.
// Bila parameter id tidak ada, ambil id pesanan dari situ.
if ($id === 0 && !empty($_GET["order_id"])
    && preg_match('/^PBL-(\d+)-/', (string)$_GET["order_id"], $m)) {
    $id = (int)$m[1];
}

// ---------- Refund ke dompet (maks 24 jam sejak pembayaran pertama) ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "refund") {

    if (!csrf_validate()) {
        set_flash("gagal", "Sesi tidak valid. Silakan coba lagi.");
        redirect("/mahasiswa/pesanan.php?id=" . $id);
    }

    try {
        // Tagihan Midtrans yang masih menunggu ikut ditutup setelah commit
        $stmt = $pdo->prepare("
            SELECT order_id FROM pembayaran
            WHERE id_pesanan = :id AND status_bayar = 'Menunggu'
              AND metode_pembayaran <> 'Dompet'
        ");
        $stmt->execute([":id" => $id]);
        $orderIdsMenunggu = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT set_config('app.aktor', :aktor, true)
        ");
        $stmt->execute([":aktor" => "mahasiswa:" . ($_SESSION["username"] ?? "")]);

        $stmt = $pdo->prepare("SELECT out_ok, out_pesan, out_jumlah FROM fn_refund_pesanan(:id, :akun)");
        $stmt->execute([":id" => $id, ":akun" => $_SESSION["id_akun"]]);
        $hasil = $stmt->fetch();

        if (!$hasil["out_ok"]) {
            $pdo->rollBack();
            set_flash("gagal", $hasil["out_pesan"]);
            redirect("/mahasiswa/pesanan.php?id=" . $id);
        }

        $pdo->commit();

        refresh_mv_rekap($pdo);
        foreach ($orderIdsMenunggu as $oid) {
            [, $pesanClose] = midtrans_batalkan($oid);
            error_log("Close Midtrans {$oid}{$pesanClose}");
        }

        set_flash("sukses", "Pesanan dibatalkan. Rp " . number_format((float)$hasil["out_jumlah"], 0, ",", ".") . " dikembalikan ke dompet Anda.");
        redirect("/mahasiswa/dompet.php");
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Refund gagal: " . $e->getMessage());
        set_flash("gagal", "Terjadi kesalahan saat memproses refund. Coba lagi.");
        redirect("/mahasiswa/pesanan.php?id=" . $id);
    }
}

// Status pembayaran terakhir pesanan ini
$stmt = $pdo->prepare("
    SELECT status_bayar, payment_url
    FROM pembayaran
    WHERE id_pesanan = :id
    ORDER BY id_pembayaran DESC
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$pembayaran = $stmt->fetch();

$flash = take_flash();

// Pastikan pesanan ada DAN milik mahasiswa yang sedang login
$stmt = $pdo->prepare("
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga,
           p.status_pesanan, p.tipe_pesanan, p.persentase_dp,
           g.alamat, g.status_pengiriman, g.tanggal_pengiriman
    FROM pesanan p
    LEFT JOIN pengiriman g ON g.id_pesanan = p.id_pesanan
    WHERE p.id_pesanan = :id AND p.id_akun = :id_akun
");
$stmt->execute([":id" => $id, ":id_akun" => $_SESSION["id_akun"]]);
$pesanan = $stmt->fetch();

// Total yang sudah berhasil dibayar + sisa tagihan
$terbayar = 0.0;
$sisa     = 0.0;
if ($pesanan !== false) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(jumlah_bayar), 0)
        FROM pembayaran
        WHERE id_pesanan = :id AND status_bayar = 'Berhasil'
    ");
    $stmt->execute([":id" => $id]);
    $terbayar = (float)$stmt->fetchColumn();
    $sisa     = max(0, (float)$pesanan["total_harga"] - $terbayar);
}

// Batas refund: 24 jam sejak pembayaran pertama berhasil
$batasRefund = null;
if ($pesanan !== false && in_array($pesanan["status_pesanan"], ["DP Lunas", "Menunggu Pelunasan", "Lunas"], true)) {
    $stmt = $pdo->prepare("
        SELECT MIN(COALESCE(dibayar_pada, tanggal_pembayaran))
        FROM pembayaran
        WHERE id_pesanan = :id AND status_bayar = 'Berhasil'
    ");
    $stmt->execute([":id" => $id]);
    $pertama = $stmt->fetchColumn();
    if ($pertama) {
        $batasRefund = (new DateTimeImmutable($pertama))->modify("+24 hours");
    }
}
$bisaRefund = $batasRefund !== null && $batasRefund > new DateTimeImmutable("now");

$detail = [];
if ($pesanan !== false) {
    $stmt = $pdo->prepare("
        SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama_merchandise
        FROM detail_pesanan d
        JOIN merchandise m ON m.id_merchandise = d.id_merchandise
        WHERE d.id_pesanan = :id
        ORDER BY d.id_detail
    ");
    $stmt->execute([":id" => $id]);
    $detail = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pesanan #<?= $id ?> - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
</head>
<body>
<?php
$mhsActive = "pesanan";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>

<main>
    <?php if ($pesanan === false): ?>

        <h1>Pesanan tidak ditemukan</h1>
        <p><a href="/mahasiswa/katalog.php">&larr; Kembali ke Katalog</a></p>

    <?php else: ?>

        <?php if ($flash): ?>
            <p style="color:<?= $flash["type"] === "sukses" ? "#0a7d32" : "#b00020" ?>;">
                <b><?= e($flash["msg"]) ?></b>
            </p>
        <?php endif; ?>

        <?php if ($baru): ?>
            <p style="color:#0a7d32;"><b>✅ Pesanan berhasil dibuat! Simpan nomor pesanan di bawah ini.</b></p>
        <?php endif; ?>

        <h1>Pesanan #<?= (int)$pesanan["id_pesanan"] ?></h1>

        <p><a href="/mahasiswa/katalog.php">Lanjut belanja</a> | <a href="/mahasiswa/riwayat.php">Riwayat Pesanan</a> | <a href="/mahasiswa/dashboard.php">Dashboard</a></p>

        <table cellpadding="6">
            <tr>
                <td><b>Tanggal Pesanan</b></td>
                <td><?= e(substr($pesanan["tanggal_pesanan"], 0, 16)) ?></td>
            </tr>
            <tr>
                <td><b>Status Pesanan</b></td>
                <td><b><?= e($pesanan["status_pesanan"]) ?></b></td>
            </tr>
            <tr>
                <td><b>Status Pembayaran</b></td>
                <td><?= e($pembayaran["status_bayar"] ?? "Belum ada transaksi") ?></td>
            </tr>
            <?php if ((int)$pesanan["persentase_dp"] > 0): ?>
            <tr>
                <td><b>Persentase DP</b></td>
                <td><?= (int)$pesanan["persentase_dp"] ?>% (Rp <?= number_format((float)$pesanan["total_harga"] * (int)$pesanan["persentase_dp"] / 100, 0, ",", ".") ?>)</td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><b>Sudah Dibayar</b></td>
                <td>Rp <?= number_format($terbayar, 0, ",", ".") ?></td>
            </tr>
            <?php if ($sisa > 0): ?>
            <tr>
                <td><b>Sisa Tagihan</b></td>
                <td><b>Rp <?= number_format($sisa, 0, ",", ".") ?></b></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><b>Tipe</b></td>
                <td><?= e($pesanan["tipe_pesanan"]) ?></td>
            </tr>
            <tr>
                <td><b>Alamat Pengiriman</b></td>
                <td><?= nl2br(e($pesanan["alamat"] ?? "-")) ?></td>
            </tr>
            <tr>
                <td><b>Status Pengiriman</b></td>
                <td><?= e($pesanan["status_pengiriman"] ?? "Belum Dikirim") ?></td>
            </tr>
        </table>

        <h2>Item Pesanan</h2>

        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Produk</th>
                <th>Harga Satuan</th>
                <th>Jumlah</th>
                <th>Subtotal</th>
            </tr>
            <?php foreach ($detail as $d): ?>
                <tr>
                    <td><?= e($d["nama_merchandise"]) ?></td>
                    <td>Rp <?= number_format((float)$d["harga_satuan"], 0, ",", ".") ?></td>
                    <td><?= (int)$d["jumlah"] ?></td>
                    <td>Rp <?= number_format((float)$d["subtotal"], 0, ",", ".") ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3" align="right"><b>Total</b></td>
                <td><b>Rp <?= number_format((float)$pesanan["total_harga"], 0, ",", ".") ?></b></td>
            </tr>
        </table>

        <?php
        $statusPesanan = $pesanan["status_pesanan"];
        $bisaBayar    = in_array($statusPesanan, ["Menunggu Pembayaran", "DP Lunas", "Menunggu Pelunasan"], true);

        $labelBayar = "Bayar Sekarang";
        if ($statusPesanan === "Menunggu Pembayaran" && (int)$pesanan["persentase_dp"] > 0) {
            $labelBayar = "Bayar DP Sekarang";
        } elseif ($statusPesanan === "DP Lunas" || $statusPesanan === "Menunggu Pelunasan") {
            $labelBayar = "Bayar Pelunasan";
        }
        ?>

        <?php if ($bisaBayar): ?>
            <p>
                <a href="/mahasiswa/bayar.php?id=<?= (int)$pesanan["id_pesanan"] ?>"><b><?= e($labelBayar) ?> &rarr;</b></a>
                <?php if (($pembayaran["status_bayar"] ?? "") === "Menunggu" && !empty($pembayaran["payment_url"])): ?>
                    | <a href="<?= e($pembayaran["payment_url"]) ?>">Lanjutkan pembayaran sebelumnya</a>
                <?php endif; ?>
            </p>
            <p><i>Pembayaran online (Midtrans) — selesaikan sebelum tautan kedaluwarsa.</i></p>
        <?php elseif ($statusPesanan === "Lunas"): ?>
            <p><b>Pesanan sudah lunas.</b> Menunggu diproses oleh admin.</p>
        <?php endif; ?>

        <?php if ($bisaRefund): ?>
            <h2>Batalkan &amp; Refund</h2>
            <p>
                Tidak jadi membeli? Pesanan bisa dibatalkan dan seluruh pembayaran
                (Rp <?= number_format($terbayar, 0, ",", ".") ?>) dikembalikan ke
                <a href="/mahasiswa/dompet.php">dompet</a> Anda.
                Batas refund: <b><?= e($batasRefund->format("d M Y H:i")) ?></b>.
            </p>
            <form method="POST" onsubmit="return confirm('Batalkan pesanan ini dan refund ke dompet?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="refund">
                <button type="submit"><b>Batalkan &amp; Refund ke Dompet</b></button>
            </form>
        <?php elseif ($batasRefund !== null): ?>
            <p><small>Batas refund otomatis (24 jam sejak pembayaran) sudah lewat. Hubungi customer service bila ingin membatalkan.</small></p>
        <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>
