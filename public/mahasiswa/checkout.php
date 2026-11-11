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
 *
 * TAMPILAN: assets/css/checkout.css. Logika PHP/backend TIDAK diubah; satu-satunya tambahan:
 *   (1) kolom m.foto pada query ringkasan + kunci "foto" di $items (foto produk di kartu Ringkasan Pesanan),
 *   (2) require gambar.php + fungsi foto_url()/foto_thumb_url() (sama seperti keranjang.php & pesanan.php),
 *   (3) atribut "selected" pada opsi alamat default bila textarea sedang berisi alamat default.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_once __DIR__ . "/../../config/dompet.php";
require_once __DIR__ . "/../../config/gambar.php";   // thumb_nama(): thumbnail foto produk di Ringkasan Pesanan
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
        SELECT m.nama_merchandise, m.harga, m.foto, k.nama_kategori
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
        "foto"     => (string)($p["foto"] ?? ""),
        "harga"    => (float)$p["harga"],
        "jumlah"   => $jumlah,
        "subtotal" => $subtotal,
    ];
    $total += $subtotal;
}

// Nilai awal textarea: input terakhir (jika gagal) > alamat default profil
$alamatForm = array_key_exists("alamat", $_POST) ? $_POST["alamat"] : ($alamatDefault ?: "");

/** URL foto produk (absolut dari root web); string kosong jika file tidak ada. */
if (!function_exists("foto_url")) {
    function foto_url(string $foto): string
    {
        $nama = basename($foto);
        return ($nama !== "" && is_file(__DIR__ . "/../uploads/merchandise/" . $nama))
            ? "/uploads/merchandise/" . $nama
            : "";
    }
}

/** Foto kecil untuk daftar item: thumbnail bila ada, jika tidak foto asli (sama seperti katalog). */
if (!function_exists("foto_thumb_url")) {
    function foto_thumb_url(string $foto): string
    {
        if ($foto === "") {
            return "";
        }
        $thumb = thumb_nama($foto);
        return is_file(__DIR__ . "/../uploads/merchandise/" . $thumb)
            ? "/uploads/merchandise/" . $thumb
            : foto_url($foto);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - Merchandise Kampus</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/checkout.css?v=<?= @filemtime(__DIR__ . "/../assets/css/checkout.css") ?>">
</head>
<body>
<?php
$mhsActive = "";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>

<main class="co">
<div class="co__inner">

    <!-- ===== NAVIGASI ATAS: ikon home + tombol "Kembali ke Keranjang" (ikon arrow-left) ===== -->
    <nav class="co-crumb" aria-label="Navigasi halaman">
        <a class="co-crumb__home" href="/mahasiswa/dashboard.php" aria-label="Dashboard"><img src="/assets/img/icon-home.png" alt=""></a>
        <a class="co-back" href="/mahasiswa/keranjang.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4M10 6l-6 6 6 6"/></svg>
            <span>Kembali ke Keranjang</span>
        </a>
    </nav>

    <!-- ===== HEADER: ikon keranjang oranye + judul + sub-judul ===== -->
    <header class="co-head">
        <span class="co-head__icon"><img src="/assets/img/checkout-ic-cart.png" alt=""></span>
        <div>
            <h1 class="co-head__title">Checkout</h1>
            <p class="co-head__sub">Proses melakukan pembayaran</p>
        </div>
    </header>

    <!-- PHP: PESAN ERROR DARI BACKEND ($error) — kondisi if asli dipertahankan -->
    <?php if ($error !== ""): ?>
        <p class="alert alert--error" role="alert"><b><?= e($error) ?></b></p>
    <?php endif; ?>

    <!-- PHP: JIKA KERANJANG KOSONG ($items kosong) — kondisi if/else asli dipertahankan -->
    <?php if (!$items): ?>
        <section class="co-card co-card--kosong">
            <p>Keranjang kosong.</p>
            <p><a href="/mahasiswa/katalog.php"><b>Lihat Katalog &rarr;</b></a></p>
        </section>
    <?php else: ?>

        <!-- ===== CARD 1: RINGKASAN PESANAN (tabel diganti grid; kolom header & baris memakai .co-grid yang sama) ===== -->
        <section class="co-card" aria-labelledby="co-t-ringkasan">
            <h2 class="co-card__title" id="co-t-ringkasan"><img class="co-ic--pkg" src="/assets/img/checkout-ic-package.png" alt="">Ringkasan Pesanan</h2>

            <div class="co-table" role="table" aria-label="Ringkasan pesanan">
                <div class="co-thead co-grid" role="row">
                    <span class="co-th co-th--name" role="columnheader">Produk</span>
                    <span class="co-th" role="columnheader">Harga Satuan</span>
                    <span class="co-th" role="columnheader">Jumlah</span>
                    <span class="co-th" role="columnheader">Subtotal</span>
                </div>

                <div role="rowgroup">
                    <!-- PHP: LOOPING ITEM PESANAN MULAI DARI SINI (foreach asli, variabel $item tidak diubah) -->
                    <?php foreach ($items as $item): ?>
                        <?php $fotoItem = foto_thumb_url($item["foto"]); ?>
                        <div class="co-row co-grid" role="row">
                            <div class="co-cell co-cell--photo" role="cell">
                                <?php if ($fotoItem !== ""): ?>
                                    <img src="<?= e($fotoItem) ?>" alt="<?= e($item["nama"]) ?>" loading="lazy">
                                <?php else: ?>
                                    <!-- Belum ada foto di database: ikon kardus sebagai placeholder -->
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-label="Tanpa foto"><path d="M12 2.8 20 7.2v9.6L12 21.2 4 16.8V7.2z"/><path d="M4.3 7.4 12 11.8l7.7-4.4M12 11.8v9.2"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="co-cell co-cell--name" role="cell">
                                <?= e($item["nama"]) ?>
                                <small class="co-cat"><?= e($item["kategori"]) ?></small>
                            </div>
                            <div class="co-cell co-cell--price" role="cell" data-label="Harga Satuan">Rp. <?= number_format($item["harga"], 0, ",", ".") ?></div>
                            <div class="co-cell co-cell--qty" role="cell" data-label="Jumlah"><?= $item["jumlah"] ?></div>
                            <div class="co-cell co-cell--sub" role="cell" data-label="Subtotal">Rp. <?= number_format($item["subtotal"], 0, ",", ".") ?></div>
                        </div>
                    <?php endforeach; ?>
                    <!-- PHP: LOOPING ITEM PESANAN SELESAI DI SINI -->
                </div>

                <!-- PHP: TOTAL PEMBAYARAN ($total) -->
                <div class="co-total co-grid" role="row">
                    <span class="co-total__label" role="cell">Total</span>
                    <span class="co-total__value" role="cell">Rp. <?= number_format($total, 0, ",", ".") ?></span>
                </div>
            </div>
        </section>

        <!-- FORM ASLI: method POST, csrf_token, name jenis_pembayaran / pakai_saldo / alamat TIDAK diubah -->
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <!-- ===== CARD 2: METODE PEMBAYARAN (radio & checkbox kustom; input asli disembunyikan, bukan dihapus) ===== -->
            <section class="co-card co-card--bayar" aria-labelledby="co-t-bayar">
                <h2 class="co-card__title" id="co-t-bayar">
                    <svg class="co-ic--pay" viewBox="0 0 48 38" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" aria-hidden="true"><rect x="2" y="2" width="44" height="34" rx="4.5"/><path d="M2 13h44"/><circle cx="10" cy="26" r="1.2" fill="currentColor"/></svg>
                    Metode Pembayaran
                </h2>

                <div class="co-opts">
                    <!-- PHP: OPSI FULL PAYMENT (name="jenis_pembayaran" value="full", checked) -->
                    <div class="co-opt">
                        <input class="co-opt__input" type="radio" id="pay-full" name="jenis_pembayaran" value="full" checked>
                        <label class="co-opt__row" for="pay-full">
                            <span class="co-opt__mark" aria-hidden="true"></span>
                            <span>Full payment - bayar penuh (Rp. <?= number_format($total, 0, ",", ".") ?>)</span>
                        </label>
                    </div>

                    <!-- PHP: OPSI DP 50% (name="jenis_pembayaran" value="dp") -->
                    <div class="co-opt">
                        <input class="co-opt__input" type="radio" id="pay-dp" name="jenis_pembayaran" value="dp">
                        <label class="co-opt__row" for="pay-dp">
                            <span class="co-opt__mark" aria-hidden="true"></span>
                            <span>DP 50% - bayar Rp. <?= number_format($total / 2, 0, ",", ".") ?>, sisanya Rp. <?= number_format($total / 2, 0, ",", ".") ?> pelunasan</span>
                        </label>
                    </div>

                    <!-- PHP: GUNAKAN SALDO DOMPET — hanya tampil jika $saldoDompet > 0 (logika asli dipertahankan; baris didesain sama dengan opsi di atas) -->
                    <?php if ($saldoDompet > 0): ?>
                        <div class="co-opt co-opt--check">
                            <input class="co-opt__input" type="checkbox" id="pay-saldo" name="pakai_saldo" value="1" checked>
                            <label class="co-opt__row" for="pay-saldo">
                                <span class="co-opt__mark" aria-hidden="true"></span>
                                <span>
                                    Gunakan saldo dompet (tersedia Rp. <?= number_format($saldoDompet, 0, ",", ".") ?>)
                                    <small class="co-opt__note">Saldo dipakai lebih dulu; kekurangannya dibayar lewat Midtrans.</small>
                                </span>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ===== CARD 3: ALAMAT PENGIRIMAN ===== -->
            <section class="co-card co-card--alamat" aria-labelledby="co-t-alamat">
                <h2 class="co-card__title" id="co-t-alamat"><img class="co-ic--home" src="/assets/img/checkout-ic-home.png" alt="">Alamat Pengiriman</h2>

                <div class="co-addr">
                    <!-- PHP: DROPDOWN ALAMAT TERSIMPAN — hanya tampil jika $daftarAlamat ada (if & foreach asli, onchange asli) -->
                    <?php if ($daftarAlamat): ?>
                        <div class="co-field">
                            <label class="co-label" for="pilih-alamat">Pilih Alamat Pengiriman</label>
                            <div class="co-pick">
                                <div class="co-select">
                                    <select id="pilih-alamat" onchange="if (this.value !== '') document.getElementById('alamat').value = this.options[this.selectedIndex].dataset.alamat;">
                                        <option value="">— ketik alamat lain —</option>
                                        <!-- PHP: LOOPING ALAMAT MULAI DARI SINI -->
                                        <?php foreach ($daftarAlamat as $a): ?>
                                            <option value="<?= (int)$a["id_alamat"] ?>" data-alamat="<?= e($a["alamat"]) ?>"<?= ($a["is_default"] && $alamatForm === $alamatDefault) ? " selected" : "" ?>>
                                                <?= e($a["label"]) ?><?= $a["is_default"] ? " (default)" : "" ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <!-- PHP: LOOPING ALAMAT SELESAI DI SINI -->
                                    </select>
                                </div>
                                <a class="co-manage" href="/mahasiswa/profil.php">+ Kelola Alamat</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- PHP: TEXTAREA ALAMAT (id="alamat" name="alamat"; isi dari $alamatForm) -->
                    <div class="co-field">
                        <label class="co-label" for="alamat">Alamat Lengkap</label>
                        <textarea class="co-textarea" id="alamat" name="alamat" rows="4" cols="50" maxlength="500"
                                  placeholder="Contoh: Jl. Kenanga No. 10, RT 02/RW 03, Surabaya"
                                  required><?= e($alamatForm) ?></textarea>
                        <small class="co-hint">Alamat default terisi otomatis (tetap bisa diubah per pesanan).</small>
                    </div>
                </div>
            </section>

            <!-- ===== TOMBOL BUAT PESANAN (kanan bawah; type="submit" form asli) ===== -->
            <div class="co-actions">
                <button type="submit" class="co-submit">
                    <img src="/assets/img/checkout-ic-truck.png" alt="">
                    <span>Buat Pesanan</span>
                    <svg viewBox="0 0 26 16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 8h22M17 2l7 6-7 6"/></svg>
                </button>
            </div>
        </form>

        <!-- TODO fase 4: setelah pesanan dibuat, lanjut ke pembayaran Midtrans -->

    <?php endif; ?>

</div>
</main>

</body>
</html>
