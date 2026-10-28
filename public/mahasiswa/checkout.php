<?php

/**
 * Checkout — proses pembuatan pesanan (fase 3B).
 *
 * Alur:
 *   1. Kunci baris produk (SELECT ... FOR UPDATE) agar stok tidak berubah
 *      oleh transaksi lain di tengah proses.
 *   2. Validasi ulang stok semua item.
 *   3. INSERT pesanan (status 'Menunggu Pembayaran', tipe 'Ready Stock').
 *   4. INSERT detail_pesanan — harga_satuan = SNAPSHOT harga saat checkout.
 *   5. UPDATE stok tiap produk (stok = stok - jumlah).
 *   6. INSERT pengiriman (alamat dari mahasiswa, status 'Belum Dikirim',
 *      id_admin NULL sampai admin ditugaskan).
 *   7. COMMIT — jika ada satu saja kegagalan, ROLLBACK semuanya.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_once __DIR__ . "/../../config/dompet.php";
require_mahasiswa();

$error = "";

$keranjang = $_SESSION["keranjang"] ?? [];
if (empty($keranjang)) {
    redirect("/mahasiswa/keranjang.php");
}

$saldoDompet = dompet_saldo($pdo, (int)$_SESSION["id_akun"]);

// Alamat tersimpan di profil (default lebih dulu) untuk prefilled form checkout
$stmt = $pdo->prepare("
    SELECT id_alamat, label, alamat, is_default
    FROM alamat_mahasiswa
    WHERE id_akun = :id_akun
    ORDER BY is_default DESC, id_alamat
");
$stmt->execute([":id_akun" => $_SESSION["id_akun"]]);
$daftarAlamat  = $stmt->fetchAll();
$alamatDefault = ($daftarAlamat && $daftarAlamat[0]["is_default"]) ? $daftarAlamat[0]["alamat"] : "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $alamat = trim($_POST["alamat"] ?? "");

        // Pilihan metode: 'full' (bayar penuh) atau 'dp' (DP 50%, sisanya pelunasan)
        $jenisPembayaran = ($_POST["jenis_pembayaran"] ?? "full") === "dp" ? "dp" : "full";
        $persenDp        = $jenisPembayaran === "dp" ? 50 : 0;

        if ($alamat === "") {
            $error = "Alamat pengiriman wajib diisi.";
        } elseif (strlen($alamat) > 500) {
            $error = "Alamat maksimal 500 karakter.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Kunci + ambil data produk terkini (FOR UPDATE)
                $ids = array_map("intval", array_keys($keranjang));
                $placeholder = implode(",", array_fill(0, count($ids), "?"));
                $stmt = $pdo->prepare("
                    SELECT id_merchandise, nama_merchandise, harga, stok
                    FROM merchandise
                    WHERE id_merchandise IN ($placeholder)
                    FOR UPDATE
                ");
                $stmt->execute($ids);

                $produkDb = [];
                foreach ($stmt->fetchAll() as $row) {
                    $produkDb[(int)$row["id_merchandise"]] = $row;
                }

                // Produk yang sudah dihapus admin dibuang dari keranjang
                foreach (array_keys($keranjang) as $kid) {
                    if (!isset($produkDb[(int)$kid])) {
                        unset($keranjang[$kid]);
                    }
                }

                if (empty($keranjang)) {
                    $pdo->rollBack();
                    $_SESSION["keranjang"] = $keranjang;
                    set_flash("gagal", "Semua item di keranjang sudah tidak tersedia.");
                    redirect("/mahasiswa/keranjang.php");
                }

                // 2. Validasi stok tiap item
                $kekurangan = [];
                $total = 0.0;
                foreach ($keranjang as $kid => $item) {
                    $p      = $produkDb[(int)$kid];
                    $jumlah = max(1, (int)($item["jumlah"] ?? 1));

                    if ($jumlah > (int)$p["stok"]) {
                        $kekurangan[] = $p["nama_merchandise"] . " (tersedia " . $p["stok"] . ")";
                    }
                    $total += (float)$p["harga"] * $jumlah;
                }

                if ($kekurangan) {
                    $pdo->rollBack();
                    set_flash("gagal", "Stok tidak cukup untuk: " . implode(", ", $kekurangan) . ". Silakan perbarui keranjang.");
                    redirect("/mahasiswa/keranjang.php");
                }

                // 3. INSERT pesanan — status & persentase sesuai metode
                $statusAwal  = $jenisPembayaran === "dp" ? "Menunggu DP" : "Menunggu Pembayaran";
                $persenDb    = $jenisPembayaran === "dp" ? $persenDp : 0;
                $jumlahDibayar = $jenisPembayaran === "dp"
                    ? $total * $persenDp / 100
                    : $total;

                $stmt = $pdo->prepare("
                    INSERT INTO pesanan
                        (id_akun, total_harga, status_pesanan, tipe_pesanan, persentase_dp)
                    VALUES
                        (:id_akun, :total, :status, 'Ready Stock', :persen)
                    RETURNING id_pesanan
                ");
                $stmt->execute([
                    ":id_akun" => $_SESSION["id_akun"],
                    ":total"   => number_format($total, 2, ".", ""),
                    ":status"  => $statusAwal,
                    ":persen"  => $persenDb,
                ]);
                $idPesanan = (int)$stmt->fetchColumn();

                // 4. INSERT detail_pesanan (snapshot harga) + 5. UPDATE stok
                $stmtDetail = $pdo->prepare("
                    INSERT INTO detail_pesanan
                        (id_pesanan, id_merchandise, jumlah, harga_satuan, subtotal)
                    VALUES
                        (:id_pesanan, :id_merchandise, :jumlah, :harga_satuan, :subtotal)
                ");
                $stmtStok = $pdo->prepare("
                    UPDATE merchandise SET stok = stok - :jumlah
                    WHERE id_merchandise = :id
                ");

                foreach ($keranjang as $kid => $item) {
                    $p        = $produkDb[(int)$kid];
                    $jumlah   = max(1, (int)($item["jumlah"] ?? 1));
                    $harga    = (float)$p["harga"];
                    $subtotal = $harga * $jumlah;

                    $stmtDetail->execute([
                        ":id_pesanan"     => $idPesanan,
                        ":id_merchandise" => (int)$kid,
                        ":jumlah"         => $jumlah,
                        ":harga_satuan"   => number_format($harga, 2, ".", ""),
                        ":subtotal"       => number_format($subtotal, 2, ".", ""),
                    ]);
                    $stmtStok->execute([":jumlah" => $jumlah, ":id" => (int)$kid]);
                }

                // 6. INSERT pengiriman (admin diisi kemudian)
                $stmt = $pdo->prepare("
                    INSERT INTO pengiriman (id_pesanan, alamat, status_pengiriman)
                    VALUES (:id_pesanan, :alamat, 'Belum Dikirim')
                ");
                $stmt->execute([":id_pesanan" => $idPesanan, ":alamat" => $alamat]);

                $pdo->commit();

                // Pesanan selesai → keranjang dikosongkan
                unset($_SESSION["keranjang"]);

                // Belum punya alamat tersimpan → simpan alamat ini sebagai default
                if (!$daftarAlamat) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO alamat_mahasiswa (id_akun, label, alamat, is_default)
                            VALUES (:id_akun, 'Alamat utama', :alamat, TRUE)
                        ");
                        $stmt->execute([":alamat" => $alamat, ":id_akun" => $_SESSION["id_akun"]]);
                    } catch (PDOException $e) {
                        error_log("Gagal menyimpan alamat default: " . $e->getMessage());
                    }
                }

                // Fase 4: buat pembayaran lalu arahkan ke sana
                // (DP → gross sebesar persentase; Full → gross sebesar total)
                // Saldo dompet (bila dipilih) dipakai lebih dulu, sisanya via Midtrans.
                [$okBayar, $tipeBayar, $hasilBayar] = dompet_bayar_pesanan(
                    $pdo,
                    $idPesanan,
                    $jumlahDibayar,
                    $jenisPembayaran === "dp" ? "DP" : "Full Payment",
                    !empty($_POST["pakai_saldo"])
                );

                if ($okBayar && $tipeBayar === "lunas") {
                    refresh_mv_rekap($pdo);
                    set_flash("sukses", "Pembayaran berhasil dari saldo dompet.");
                    redirect("/mahasiswa/pesanan.php?id=" . $idPesanan);
                }

                if ($okBayar) {
                    redirect($hasilBayar); // keluar ke halaman pembayaran Midtrans
                }

                set_flash("gagal", "Pesanan #{$idPesanan} dibuat, namun pembayaran online gagal dibuat: {$hasilBayar} Silakan bayar lewat halaman pesanan.");
                redirect("/mahasiswa/pesanan.php?id=" . $idPesanan);

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Checkout gagal: " . $e->getMessage());
                $error = "Terjadi kesalahan saat memproses pesanan. Silakan coba lagi.";
            }
        }
    }
}

// ---------- Ringkasan untuk tampilan ----------

$items = [];
$total = 0.0;

foreach ($keranjang as $id => $item) {
    $stmt = $pdo->prepare("
        SELECT m.nama_merchandise, m.harga, k.nama_kategori
        FROM merchandise m
        JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
        WHERE m.id_merchandise = :id
    ");
    $stmt->execute([":id" => (int)$id]);
    $p = $stmt->fetch();

    if ($p === false) {
        continue;
    }

    $jumlah   = max(1, (int)($item["jumlah"] ?? 1));
    $subtotal = (float)$p["harga"] * $jumlah;

    $items[] = [
        "nama"     => $p["nama_merchandise"],
        "kategori" => $p["nama_kategori"],
        "harga"    => (float)$p["harga"],
        "jumlah"   => $jumlah,
        "subtotal" => $subtotal,
    ];
    $total += $subtotal;
}

// Nilai awal textarea: input terakhir (jika gagal) > alamat default profil
$alamatForm = array_key_exists("alamat", $_POST) ? $_POST["alamat"] : ($alamatDefault ?: "");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>

<main>
    <h1>Checkout</h1>
    <p><a href="/mahasiswa/keranjang.php">&larr; Kembali ke Keranjang</a></p>

    <?php if ($error !== ""): ?>
        <p style="color:#b00020;"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <?php if (!$items): ?>
        <p>Keranjang kosong.</p>
        <p><a href="/mahasiswa/katalog.php"><b>Lihat Katalog &rarr;</b></a></p>
    <?php else: ?>

        <h2>Ringkasan Pesanan</h2>

        <table border="1" cellpadding="8" cellspacing="0">
            <tr>
                <th>Produk</th>
                <th>Harga</th>
                <th>Jumlah</th>
                <th>Subtotal</th>
            </tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item["nama"]) ?> <small>(<?= e($item["kategori"]) ?>)</small></td>
                    <td>Rp <?= number_format($item["harga"], 0, ",", ".") ?></td>
                    <td><?= $item["jumlah"] ?></td>
                    <td>Rp <?= number_format($item["subtotal"], 0, ",", ".") ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3" align="right"><b>Total Pembayaran</b></td>
                <td><b>Rp <?= number_format($total, 0, ",", ".") ?></b></td>
            </tr>
        </table>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <h2>Metode Pembayaran</h2>

        <p>
            <label><input type="radio" name="jenis_pembayaran" value="full" checked>
                Full Payment — bayar penuh (Rp <?= number_format($total, 0, ",", ".") ?>)</label><br>
            <label><input type="radio" name="jenis_pembayaran" value="dp">
                DP 50% — bayar Rp <?= number_format($total / 2, 0, ",", ".") ?> dulu,
                sisanya Rp <?= number_format($total / 2, 0, ",", ".") ?> pelunasan</label>
        </p>

        <?php if ($saldoDompet > 0): ?>
            <p>
                <label><input type="checkbox" name="pakai_saldo" value="1" checked>
                    Gunakan saldo dompet (tersedia Rp <?= number_format($saldoDompet, 0, ",", ".") ?>)</label><br>
                <small>Saldo dipakai lebih dulu; kekurangannya dibayar lewat Midtrans.</small>
            </p>
        <?php endif; ?>

        <h2>Alamat Pengiriman</h2>

            <?php if ($daftarAlamat): ?>
                <p>
                    <label for="pilih-alamat">Pilih alamat tersimpan</label><br>
                    <select id="pilih-alamat" onchange="if (this.value !== '') document.getElementById('alamat').value = this.options[this.selectedIndex].dataset.alamat;">
                        <option value="">— ketik alamat lain —</option>
                        <?php foreach ($daftarAlamat as $a): ?>
                            <option value="<?= (int)$a["id_alamat"] ?>" data-alamat="<?= e($a["alamat"]) ?>">
                                <?= e($a["label"]) ?><?= $a["is_default"] ? " (default)" : "" ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small><a href="/mahasiswa/profil.php">Kelola alamat</a></small>
                </p>
            <?php endif; ?>

            <textarea id="alamat" name="alamat" rows="4" cols="50" maxlength="500"
                      placeholder="Contoh: Jl. Kenanga No. 10, RT 02/RW 03, Surabaya"
                      required><?= e($alamatForm) ?></textarea>
            <br><small>Alamat default terisi otomatis (tetap bisa diubah per pesanan).</small>

            <p>
                <button type="submit"><b>Buat Pesanan</b></button>
            </p>
        </form>

        <!-- TODO fase 4: setelah pesanan dibuat, lanjut ke pembayaran Midtrans -->

    <?php endif; ?>

</main>

</body>
</html>
