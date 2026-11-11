<?php

/**
 * Dompet mahasiswa: saldo, top up via Midtrans, riwayat mutasi.
 *
 * Saldo dipakai untuk membayar pesanan. Penarikan (withdraw) diproses manual
 * oleh admin: pengajuan withdraw tercatat di withdraw_dompet (saldo ditahan), lalu WhatsApp admin terbuka dengan pesan terisi
 * (nomor WA_ADMIN di config/dompet.php). Refund pesanan masuk ke sini.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_once __DIR__ . "/../../config/dompet.php";
require_mahasiswa();

$idAkun = (int)$_SESSION["id_akun"];
$error  = "";
$flash  = take_flash();

/** Tautan WhatsApp admin dengan pesan withdraw terisi (nomor admin: WA_ADMIN di config/dompet.php). */
function link_withdraw(PDO $pdo, int $idAkun, int $idWithdraw, int $jumlah, string $tujuan): string
{
    $stmt = $pdo->prepare("SELECT nim, nama FROM mahasiswa WHERE id_akun = :id");
    $stmt->execute([":id" => $idAkun]);
    $mhs = $stmt->fetch() ?: ["nim" => "-", "nama" => (string)($_SESSION["username"] ?? "")];

    $pesan = "Halo admin, saya mengajukan withdraw dompet.\n"
           . "ID permintaan: #" . $idWithdraw . "\n"
           . "Nama: " . $mhs["nama"] . "\n"
           . "NIM: " . $mhs["nim"] . "\n"
           . "Jumlah: Rp " . number_format($jumlah, 0, ",", ".") . "\n"
           . "Tujuan: " . $tujuan . "\n"
           . "Mohon diproses, terima kasih.";

    return "https://wa.me/" . preg_replace("/\D/", "", WA_ADMIN) . "?text=" . rawurlencode($pesan);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } elseif (($_POST["action"] ?? "") === "withdraw") {
        $jumlah    = (int)preg_replace("/\D/", "", (string)($_POST["jumlah"] ?? ""));
        $tujuan    = trim((string)($_POST["tujuan"] ?? ""));
        $saldoKini = dompet_saldo($pdo, $idAkun);

        if ($jumlah < WITHDRAW_MINIMAL) {
            $error = "Jumlah withdraw minimal Rp " . number_format(WITHDRAW_MINIMAL, 0, ",", ".") . ".";
        } elseif ($jumlah > $saldoKini) {
            $error = "Jumlah withdraw melebihi saldo (Rp " . number_format($saldoKini, 0, ",", ".") . ").";
        } elseif ($tujuan === "" || mb_strlen($tujuan) > 200) {
            $error = "Tujuan transfer wajib diisi (maks 200 karakter), mis. BCA 1234567890 a.n. Nama.";
        } else {
            try {
                // Permintaan dibuat + saldo ditahan dalam satu function database
                $stmt = $pdo->prepare("SELECT fn_withdraw_ajukan(:akun, :jumlah, :tujuan)");
                $stmt->execute([":akun" => $idAkun, ":jumlah" => $jumlah, ":tujuan" => $tujuan]);
                $idWd = (int)$stmt->fetchColumn();

                set_flash("sukses", "Permintaan withdraw #{$idWd} dibuat. Saldo ditahan sampai admin memproses.");
                redirect(link_withdraw($pdo, $idAkun, $idWd, $jumlah, $tujuan)); // buka WhatsApp admin
            } catch (PDOException $e) {
                error_log("Withdraw gagal: " . $e->getMessage());
                $error = str_contains($e->getMessage(), "Saldo dompet tidak cukup")
                    ? "Saldo dompet tidak cukup."
                    : "Terjadi kesalahan database. Coba lagi.";
            }
        }
    } else {
        $jumlah = (int)preg_replace("/\D/", "", (string)($_POST["jumlah"] ?? ""));

        if ($jumlah < TOPUP_MINIMAL || $jumlah > TOPUP_MAKSIMAL) {
            $error = "Jumlah top up harus antara Rp " . number_format(TOPUP_MINIMAL, 0, ",", ".")
                   . " dan Rp " . number_format(TOPUP_MAKSIMAL, 0, ",", ".") . ".";
        } else {
            try {
                [$ok, $hasil] = midtrans_buat_topup($pdo, $idAkun, $jumlah);

                if ($ok) {
                    redirect($hasil); // keluar ke halaman pembayaran Midtrans
                }
                $error = $hasil;
            } catch (PDOException $e) {
                error_log("Top up gagal: " . $e->getMessage());
                $error = "Terjadi kesalahan database. Coba lagi.";
            }
        }
    }
}

$saldo = dompet_saldo($pdo, $idAkun);

// Permintaan withdraw milik user ini (status: Diproses / Selesai / Ditolak)
$daftarWd = [];
try {
    $stmt = $pdo->prepare("
        SELECT id_withdraw, jumlah, tujuan, status, catatan_admin, created_at, diproses_pada
        FROM withdraw_dompet
        WHERE id_akun = :id
        ORDER BY id_withdraw DESC
        LIMIT 10
    ");
    $stmt->execute([":id" => $idAkun]);
    $daftarWd = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Daftar withdraw gagal dibaca: " . $e->getMessage());
}

$stmt = $pdo->prepare("
    SELECT created_at, jenis, jumlah, saldo_sesudah, id_pesanan, keterangan
    FROM mutasi_dompet
    WHERE id_akun = :id
    ORDER BY id_mutasi DESC
    LIMIT 30
");
$stmt->execute([":id" => $idAkun]);
$mutasi = $stmt->fetchAll();

// Top up yang belum dibayar (tautan masih bisa dilanjutkan)
$stmt = $pdo->prepare("
    SELECT order_id, jumlah, payment_url, created_at
    FROM topup_dompet
    WHERE id_akun = :id AND status = 'Menunggu'
      AND created_at > now() - INTERVAL '60 minutes'
    ORDER BY id_topup DESC
");
$stmt->execute([":id" => $idAkun]);
$menunggu = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dompet Saya - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar.php";
?>

<main>
    <h1>Dompet Saya</h1>
    <p>
        <a href="/mahasiswa/dashboard.php">&larr; Dashboard</a> |
        <a href="/mahasiswa/riwayat.php">Riwayat Pesanan</a>
    </p>

    <?php if ($flash): ?>
        <p style="color:<?= $flash["type"] === "sukses" ? "#0a7d32" : "#b00020" ?>;">
            <b><?= e($flash["msg"]) ?></b>
        </p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p style="color:#b00020;"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <h2>Saldo</h2>
    <p style="font-size:2rem;"><b>Rp <?= number_format($saldo, 0, ",", ".") ?></b></p>
    <p><small>Saldo dipakai untuk membayar pesanan (penuh atau sebagian). Refund pesanan masuk ke dompet ini.</small></p>

    <h2>Withdraw</h2>
    <?php if ($saldo >= WITHDRAW_MINIMAL): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="withdraw">

            <p>
                <label>Jumlah yang ingin ditarik (minimal Rp <?= number_format(WITHDRAW_MINIMAL, 0, ",", ".") ?>, maksimal sebesar saldo)</label><br>
                <input type="number" name="jumlah" min="<?= WITHDRAW_MINIMAL ?>" max="<?= (int)floor($saldo) ?>"
                       step="1" placeholder="mis. <?= WITHDRAW_MINIMAL ?>" required>
                <button type="button" onclick="this.form.jumlah.value=<?= (int)floor($saldo) ?>">Semua</button>
            </p>
            <p>
                <label>Tujuan transfer (rekening / e-wallet)</label><br>
                <input type="text" name="tujuan" maxlength="200" size="40"
                       placeholder="mis. BCA 1234567890 a.n. Nama" required>
            </p>
            <button type="submit"><b>Ajukan Withdraw</b></button>
        </form>
        <p><small>Saldo langsung ditahan, lalu Anda diarahkan ke WhatsApp admin dengan pesan yang sudah terisi. Jika ditolak, saldo kembali otomatis.</small></p>
    <?php else: ?>
        <p><small>Saldo belum cukup untuk withdraw (minimal Rp <?= number_format(WITHDRAW_MINIMAL, 0, ",", ".") ?>).</small></p>
    <?php endif; ?>

    <?php if ($daftarWd): ?>
        <h3>Permintaan Withdraw</h3>
        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>ID</th>
                <th>Diajukan</th>
                <th>Jumlah</th>
                <th>Tujuan</th>
                <th>Status</th>
                <th>Catatan admin</th>
            </tr>
            <?php foreach ($daftarWd as $w): ?>
                <?php $warna = ["Diproses" => "#b36b00", "Selesai" => "#0a7d32", "Ditolak" => "#b00020"][$w["status"]] ?? "inherit"; ?>
                <tr>
                    <td>#<?= (int)$w["id_withdraw"] ?></td>
                    <td><?= e(substr($w["created_at"], 0, 16)) ?></td>
                    <td>Rp <?= number_format((float)$w["jumlah"], 0, ",", ".") ?></td>
                    <td><?= e($w["tujuan"]) ?></td>
                    <td style="color:<?= $warna ?>;">
                        <b><?= e($w["status"]) ?></b>
                        <?php if ($w["diproses_pada"]): ?>
                            <br><small><?= e(substr($w["diproses_pada"], 0, 16)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($w["catatan_admin"] ?? "") ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p><small>Diproses = menunggu admin. Selesai = sudah ditransfer. Ditolak = saldo dikembalikan ke dompet.</small></p>
    <?php endif; ?>

    <h2>Top Up</h2>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <p>
            <label>Jumlah (Rp <?= number_format(TOPUP_MINIMAL, 0, ",", ".") ?> – <?= number_format(TOPUP_MAKSIMAL, 0, ",", ".") ?>)</label><br>
            <input type="number" name="jumlah" min="<?= TOPUP_MINIMAL ?>" max="<?= TOPUP_MAKSIMAL ?>"
                   step="1000" value="50000" required>
        </p>
        <p>
            <?php foreach ([25000, 50000, 100000, 200000] as $n): ?>
                <button type="button" onclick="this.form.jumlah.value=<?= $n ?>">Rp <?= number_format($n, 0, ",", ".") ?></button>
            <?php endforeach; ?>
        </p>
        <button type="submit"><b>Top Up via Midtrans</b></button>
    </form>

    <?php if ($menunggu): ?>
        <h3>Top up menunggu pembayaran</h3>
        <ul>
            <?php foreach ($menunggu as $t): ?>
                <li>
                    Rp <?= number_format((float)$t["jumlah"], 0, ",", ".") ?>
                    (<?= e(substr($t["created_at"], 0, 16)) ?>) —
                    <a href="<?= e($t["payment_url"]) ?>">Lanjutkan pembayaran</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2>Riwayat Mutasi</h2>

    <?php if (!$mutasi): ?>
        <p>Belum ada mutasi.</p>
    <?php else: ?>
        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th>Keterangan</th>
                <th>Jumlah</th>
                <th>Saldo</th>
            </tr>
            <?php foreach ($mutasi as $m): ?>
                <?php $masuk = (float)$m["jumlah"] > 0; ?>
                <tr>
                    <td><?= e(substr($m["created_at"], 0, 16)) ?></td>
                    <td><?= e($m["jenis"]) ?></td>
                    <td>
                        <?= e($m["keterangan"] ?? "") ?>
                        <?php if ($m["id_pesanan"]): ?>
                            (<a href="/mahasiswa/pesanan.php?id=<?= (int)$m["id_pesanan"] ?>">#<?= (int)$m["id_pesanan"] ?></a>)
                        <?php endif; ?>
                    </td>
                    <td style="color:<?= $masuk ? "#0a7d32" : "#b00020" ?>;">
                        <b><?= $masuk ? "+" : "−" ?>Rp <?= number_format(abs((float)$m["jumlah"]), 0, ",", ".") ?></b>
                    </td>
                    <td>Rp <?= number_format((float)$m["saldo_sesudah"], 0, ",", ".") ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

</main>

</body>
</html>
