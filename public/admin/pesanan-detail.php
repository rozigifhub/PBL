<?php

/**
 * Admin — detail pesanan:
 *   - info pesanan, pembeli, item, riwayat pembayaran
 *   - ubah status pesanan (transisi terbatas sesuai alur)
 *   - kelola pengiriman (status, tanggal, bukti/resi)
 *
 * Pembatalan pesanan mengembalikan stok produk otomatis.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

/** Transisi status yang sah: status sekarang → status berikutnya. */
function transisi_diizinkan(string $status): array
{
    $map = [
        "Menunggu Pembayaran" => ["Dibatalkan"],
        "Menunggu DP"         => ["Dibatalkan"],
        "Menunggu Pelunasan"  => ["Dibatalkan"],
        "DP Lunas"            => ["Diproses"],
        "Lunas"               => ["Diproses"],
        "Diproses"            => ["Dikirim"],
        "Dikirim"             => ["Selesai"],
        "Selesai"             => [],
        "Dibatalkan"          => [],
    ];
    return $map[$status] ?? [];
}

$id   = (int)($_GET["id"] ?? 0);
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            $pdo->beginTransaction();

            // Kunci baris pesanan
            $stmt = $pdo->prepare("
                SELECT id_pesanan, status_pesanan
                FROM pesanan
                WHERE id_pesanan = :id
                FOR UPDATE
            ");
            $stmt->execute([":id" => $id]);
            $pesananKini = $stmt->fetch();

            if ($pesananKini === false) {
                $pdo->rollBack();
                $error = "Pesanan tidak ditemukan.";
            } else {
                $statusKini = $pesananKini["status_pesanan"];

                // ---- Aksi: ubah status pesanan ----
                if ($action === "status") {
                    $statusBaru = $_POST["status_baru"] ?? "";

                    if (!in_array($statusBaru, transisi_diizinkan($statusKini), true)) {
                        $error = "Perubahan {$statusKini} → {$statusBaru} tidak diizinkan.";
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE pesanan SET status_pesanan = :status
                            WHERE id_pesanan = :id
                        ");
                        $stmt->execute([":status" => $statusBaru, ":id" => $id]);

                        // Pembatalan: kembalikan stok semua item
                        if ($statusBaru === "Dibatalkan") {
                            $stmt = $pdo->prepare("
                                UPDATE merchandise m
                                SET stok = m.stok + d.jumlah
                                FROM detail_pesanan d
                                WHERE d.id_merchandise = m.id_merchandise
                                  AND d.id_pesanan = :id
                            ");
                            $stmt->execute([":id" => $id]);
                        }

                        // Pesanan dikirim → pengiriman ikut diperbarui
                        if ($statusBaru === "Dikirim") {
                            $stmt = $pdo->prepare("
                                UPDATE pengiriman
                                SET status_pengiriman = 'Dikirim',
                                    tanggal_pengiriman = COALESCE(tanggal_pengiriman, now()),
                                    id_admin = COALESCE(id_admin, :admin)
                                WHERE id_pesanan = :id
                            ");
                            $stmt->execute([
                                ":admin" => $_SESSION["id_akun"],
                                ":id"    => $id,
                            ]);
                        }

                        set_flash("sukses", "Status pesanan #{$id} → {$statusBaru}.");
                    }
                }

                // ---- Aksi: kelola pengiriman ----
                if ($action === "pengiriman" && $error === "") {
                    $statusKir = $_POST["status_pengiriman"] ?? "";
                    $tanggal   = trim($_POST["tanggal_pengiriman"] ?? "");
                    $bukti     = trim($_POST["bukti_pengiriman"] ?? "");

                    if (!in_array($statusKir, ["Belum Dikirim", "Dikirim", "Diterima"], true)) {
                        $error = "Status pengiriman tidak valid.";
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE pengiriman
                            SET status_pengiriman = :status,
                                tanggal_pengiriman = COALESCE(NULLIF(:tanggal, ''), tanggal_pengiriman),
                                bukti_pengiriman   = NULLIF(:bukti, ''),
                                id_admin = COALESCE(id_admin, :admin)
                            WHERE id_pesanan = :id
                        ");
                        $stmt->execute([
                            ":status"  => $statusKir,
                            ":tanggal" => $tanggal !== "" ? $tanggal : null,
                            ":bukti"   => $bukti,
                            ":admin"   => $_SESSION["id_akun"],
                            ":id"      => $id,
                        ]);
                        set_flash("sukses", "Data pengiriman diperbarui.");
                    }
                }

                if ($error === "") {
                    $pdo->commit();
                    redirect("pesanan-detail.php?id=" . $id);
                }

                $pdo->rollBack();
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Admin pesanan-detail gagal: " . $e->getMessage());
            $error = "Terjadi kesalahan database. Coba lagi.";
        }
    }
}

// ---------- Data untuk tampilan ----------

$stmt = $pdo->prepare("
    SELECT p.id_pesanan, p.tanggal_pesanan, p.total_harga,
           p.status_pesanan, p.tipe_pesanan, p.persentase_dp,
           a.username, a.email,
           m.nim, m.nama AS nama_mhs, m.no_hp
    FROM pesanan p
    JOIN akun_login a ON a.id_akun = p.id_akun
    LEFT JOIN mahasiswa m ON m.id_akun = p.id_akun
    WHERE p.id_pesanan = :id
");
$stmt->execute([":id" => $id]);
$pesanan = $stmt->fetch();

if ($pesanan === false) {
    redirect("pesanan.php");
}

$stmt = $pdo->prepare("
    SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama_merchandise
    FROM detail_pesanan d
    JOIN merchandise m ON m.id_merchandise = d.id_merchandise
    WHERE d.id_pesanan = :id
    ORDER BY d.id_detail
");
$stmt->execute([":id" => $id]);
$detail = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT id_pembayaran, order_id, transaction_id, tanggal_pembayaran,
           metode_pembayaran, jumlah_bayar, status_bayar, jenis_pembayaran, payment_url
    FROM pembayaran
    WHERE id_pesanan = :id
    ORDER BY id_pembayaran DESC
");
$stmt->execute([":id" => $id]);
$pembayaran = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM pengiriman WHERE id_pesanan = :id");
$stmt->execute([":id" => $id]);
$pengiriman = $stmt->fetch();

$transisi = transisi_diizinkan($pesanan["status_pesanan"]);
$flash    = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pesanan #<?= (int)$pesanan["id_pesanan"] ?> - Admin</title>
</head>
<body>

<main>
    <h1>Pesanan #<?= (int)$pesanan["id_pesanan"] ?></h1>
    <p>
        <a href="pesanan.php">&larr; Daftar Pesanan</a> |
        <a href="dashboard.php">Dashboard</a>
    </p>

    <?php if ($flash): ?>
        <p><b><?= e($flash["msg"]) ?></b></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p style="color:#b00020;"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <h2>Informasi Pesanan</h2>

    <table cellpadding="6">
        <tr>
            <td><b>Tanggal</b></td>
            <td><?= e(substr($pesanan["tanggal_pesanan"], 0, 16)) ?></td>
        </tr>
        <tr>
            <td><b>Status Pesanan</b></td>
            <td><b><?= e($pesanan["status_pesanan"]) ?></b></td>
        </tr>
        <tr>
            <td><b>Tipe / DP</b></td>
            <td>
                <?= e($pesanan["tipe_pesanan"]) ?>
                <?php if ((int)$pesanan["persentase_dp"] > 0): ?>
                    — DP <?= (int)$pesanan["persentase_dp"] ?>%
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><b>Total</b></td>
            <td><b>Rp <?= number_format((float)$pesanan["total_harga"], 0, ",", ".") ?></b></td>
        </tr>
    </table>

    <h2>Pembeli</h2>

    <table cellpadding="6">
        <tr><td><b>Nama</b></td><td><?= e($pesanan["nama_mhs"] ?? $pesanan["username"]) ?></td></tr>
        <tr><td><b>NIM</b></td><td><?= e($pesanan["nim"] ?? "-") ?></td></tr>
        <tr><td><b>Email</b></td><td><?= e($pesanan["email"]) ?></td></tr>
        <tr><td><b>No. HP</b></td><td><?= e($pesanan["no_hp"] ?? "-") ?></td></tr>
        <tr>
            <td><b>Alamat Kirim</b></td>
            <td><?= nl2br(e($pengiriman["alamat"] ?? "-")) ?></td>
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

    <h2>Riwayat Pembayaran</h2>

    <?php if (!$pembayaran): ?>
        <p>Belum ada transaksi pembayaran.</p>
    <?php else: ?>
        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th>Metode</th>
                <th>Jumlah</th>
                <th>Status</th>
                <th>Order ID</th>
            </tr>
            <?php foreach ($pembayaran as $pb): ?>
                <tr>
                    <td><?= e(substr($pb["tanggal_pembayaran"], 0, 16)) ?></td>
                    <td><?= e($pb["jenis_pembayaran"]) ?></td>
                    <td><?= e($pb["metode_pembayaran"]) ?></td>
                    <td>Rp <?= number_format((float)$pb["jumlah_bayar"], 0, ",", ".") ?></td>
                    <td><b><?= e($pb["status_bayar"]) ?></b></td>
                    <td><small><?= e($pb["order_id"]) ?></small></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>Ubah Status Pesanan</h2>

    <?php if ($transisi): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="status">

            <label>Status baru</label><br>
            <select name="status_baru" required>
                <?php foreach ($transisi as $s): ?>
                    <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Terapkan</button>
        </form>
        <p><small>Transisi sah dari <b><?= e($pesanan["status_pesanan"]) ?></b>: <?= e(implode(", ", $transisi) ?: "tidak ada") ?>.</small></p>
    <?php else: ?>
        <p><i>Status <b><?= e($pesanan["status_pesanan"]) ?></b> adalah status akhir — tidak ada perubahan lagi.</i></p>
    <?php endif; ?>

    <h2>Pengiriman</h2>

    <?php if ($pengiriman !== false): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pengiriman">

            <p>
                <label>Status Pengiriman</label><br>
                <select name="status_pengiriman">
                    <?php foreach (["Belum Dikirim", "Dikirim", "Diterima"] as $s): ?>
                        <option value="<?= e($s) ?>"
                            <?= $pengiriman["status_pengiriman"] === $s ? "selected" : "" ?>>
                            <?= e($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label>Tanggal Pengiriman</label><br>
                <input type="datetime-local" name="tanggal_pengiriman"
                       value="<?= e(str_replace(" ", "T", substr($pengiriman["tanggal_pengiriman"] ?? "", 0, 16))) ?>">
            </p>
            <p>
                <label>Bukti / No. Resi (opsional)</label><br>
                <input type="text" name="bukti_pengiriman" maxlength="255"
                       value="<?= e($pengiriman["bukti_pengiriman"] ?? "") ?>">
            </p>

            <button type="submit">Simpan Pengiriman</button>
        </form>
    <?php else: ?>
        <p><i>Baris pengiriman belum ada untuk pesanan ini.</i></p>
    <?php endif; ?>

</main>

</body>
</html>
