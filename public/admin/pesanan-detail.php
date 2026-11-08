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
require_once __DIR__ . "/../../config/midtrans.php";
require_once __DIR__ . "/../../config/gambar.php";   // thumb_nama(): thumbnail foto produk di kartu Item Pesanan
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

            // Identitas aktor untuk trigger audit (berlaku dalam transaksi ini)
            $stmt = $pdo->prepare("
                SELECT set_config('app.admin_id', :admin, true),
                       set_config('app.aktor', :aktor, true)
            ");
            $stmt->execute([
                ":admin" => (string)$_SESSION["id_akun"],
                ":aktor" => "admin:" . ($_SESSION["username"] ?? ""),
            ]);

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
                    } elseif ($statusBaru === "Dibatalkan") {
                        // Pembatalan lewat function database:
                        // stok dikembalikan + status jadi 'Dibatalkan'
                        $stmt = $pdo->prepare("SELECT fn_batalkan_pesanan(:id) AS pesan");
                        $stmt->execute([":id" => $id]);

                        // Attempt pembayaran yang masih 'Menunggu' → Gagal
                        // (tautan bayar lamanya terkunci di sisi aplikasi)
                        $stmt = $pdo->prepare("
                            SELECT order_id FROM pembayaran
                            WHERE id_pesanan = :id AND status_bayar = 'Menunggu'
                        ");
                        $stmt->execute([":id" => $id]);
                        $orderIdsMenunggu = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        $stmt = $pdo->prepare("
                            UPDATE pembayaran SET status_bayar = 'Gagal'
                            WHERE id_pesanan = :id AND status_bayar = 'Menunggu'
                        ");
                        $stmt->execute([":id" => $id]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE pesanan SET status_pesanan = :status
                            WHERE id_pesanan = :id
                        ");
                        $stmt->execute([":status" => $statusBaru, ":id" => $id]);

                        // Sinkronisasi pengiriman saat 'Dikirim' ditangani
                        // trigger trg_pesanan_status di level database

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
                                tanggal_pengiriman = COALESCE(CAST(NULLIF(:tanggal, '') AS TIMESTAMPTZ), tanggal_pengiriman),
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

                    if ($action === "status" && $statusBaru === "Dibatalkan") {
                        refresh_mv_rekap($pdo);

                        // Tutup transaksi Midtrans yang masih menunggu (best-effort,
                        // dilakukan setelah commit agar lock tidak tertahan HTTP)
                        foreach (($orderIdsMenunggu ?? []) as $oid) {
                            [, $pesanClose] = midtrans_batalkan($oid);
                            error_log("Close Midtrans {$oid}{$pesanClose}");
                        }
                    }

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
    SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama_merchandise, m.foto
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

// ---------- Tampilan (tidak memengaruhi data) ----------

// Badge status pesanan: status => [warna, ikon]. Warna didefinisikan di admin-pesanan-detail.css (.pd-badge--*).
// Sama dengan pemetaan di admin/pesanan.php supaya warna status konsisten antar halaman.
$badgePesanan = [
    "Menunggu Pembayaran" => ["wait",    "pd-ic-clock"],
    "Menunggu DP"         => ["wait",    "pd-ic-clock"],
    "Menunggu Pelunasan"  => ["wait",    "pd-ic-clock"],
    "DP Lunas"            => ["done",    "pd-ic-check"],
    "Lunas"               => ["done",    "pd-ic-check"],
    "Diproses"            => ["process", "pd-ic-gear"],
    "Dikirim"             => ["ship",    "pd-ic-truck"],
    "Selesai"             => ["done",    "pd-ic-check"],
    "Dibatalkan"          => ["off",     "pd-ic-x"],
];

// Badge status pembayaran (nilai dari tabel pembayaran / Midtrans).
$badgeBayar = [
    "Menunggu"    => ["wait", "pd-ic-clock"],
    "Berhasil"    => ["done", "pd-ic-check"],
    "Gagal"       => ["fail", "pd-ic-x"],
    "Kedaluwarsa" => ["off",  "pd-ic-clock"],
];

/** Badge berikon. Status yang tidak dikenal tetap tampil (abu-abu, tanpa ikon). */
if (!function_exists("html_badge")) {
    function html_badge(array $peta, ?string $teks): string
    {
        if ($teks === null || $teks === "") {
            return '<span class="pd-none">&ndash;</span>';
        }
        [$warna, $ikon] = $peta[$teks] ?? ["off", null];
        $svg = $ikon !== null
            ? '<svg aria-hidden="true" focusable="false"><use href="#' . $ikon . '"/></svg>'
            : "";
        return '<span class="pd-badge pd-badge--' . $warna . '">' . $svg . e($teks) . '</span>';
    }
}

/** Format Rupiah mengikuti desain: "Rp. 100.000". */
if (!function_exists("format_rupiah")) {
    function format_rupiah(float $angka): string
    {
        return "Rp. " . number_format($angka, 0, ",", ".");
    }
}

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
    <title>Pesanan #<?= (int)$pesanan["id_pesanan"] ?> - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-pesanan-detail.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-pesanan-detail.css") ?>">
    <script src="/assets/js/admin-pesanan-detail.js?v=<?= @filemtime(__DIR__ . "/../assets/js/admin-pesanan-detail.js") ?>" defer></script>
</head>
<body class="page-dashboard">
<?php
$adminActive     = "pesanan";
$adminNavRingkas = true;   // desain Kelola Pesanan / Detail Pesanan: 4 menu + ikon akun saja
require __DIR__ . "/../includes/navbar-admin.php";
?>

<!-- Ikon badge status (dipakai berulang lewat <use>) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="pd-ic-check" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10" fill="currentColor"/>
        <path d="m7.6 12.4 3 3 5.8-6.2" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="pd-ic-clock" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10" fill="currentColor"/>
        <path d="M12 7v5.2l3.2 1.9" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="pd-ic-x" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10" fill="currentColor"/>
        <path d="m9 9 6 6M15 9l-6 6" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="pd-ic-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 6.5h11.5v10H2z"/>
        <path d="M13.5 10h4.2l3.3 3.4v3.1h-7.5"/>
        <circle cx="7" cy="18" r="2"/>
        <circle cx="17.5" cy="18" r="2"/>
    </symbol>
    <symbol id="pd-ic-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </symbol>
    <!-- Ikon placeholder bila produk belum punya foto -->
    <symbol id="pd-ic-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2.8 20 7.2v9.6L12 21.2 4 16.8V7.2z"/>
        <path d="M4.3 7.4 12 11.8l7.7-4.4M12 11.8v9.2"/>
    </symbol>
</svg>

<main class="dash pd-page">
    <div class="pd">

        <!-- ================= KEPALA HALAMAN: breadcrumb + ikon + judul ================= -->
        <header class="pd-head">
            <nav class="pd-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
                <span>
                    <a href="/admin/dashboard.php">Dashboard</a> /
                    <a href="/admin/pesanan.php">Kelola Pesanan</a> /
                    <a href="/admin/pesanan-detail.php?id=<?= (int)$pesanan["id_pesanan"] ?>" aria-current="page">Detail Pesanan</a>
                </span>
            </nav>
            <div class="pd-title">
                <span class="pd-title__icon" aria-hidden="true"><img src="/assets/img/icon-package.png" alt="" width="128" height="128"></span>
                <div>
                    <!-- ID pesanan diambil dari backend ($pesanan["id_pesanan"]) -->
                    <h1>Pesanan #<?= (int)$pesanan["id_pesanan"] ?></h1>
                    <p>Detail informasi pesanan dan pengelolaan status pengirim</p>
                </div>
            </div>
        </header>

        <!-- ================= PESAN FLASH & ERROR DARI BACKEND ================= -->
        <?php if ($flash): ?>
            <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="<?= ($flash["type"] ?? "") === "sukses" ? "status" : "alert" ?>">
                <?= e($flash["msg"]) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert alert--error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <!-- ================= BARIS 1 (2 kolom): INFORMASI PESANAN + PEMBELI ================= -->
        <div class="pd-grid pd-grid--info">

            <!-- ---------- CARD: INFORMASI PESANAN ---------- -->
            <section class="pd-card" aria-labelledby="pd-t-info">
                <div class="pd-card__head">
                    <h2 class="pd-card__title" id="pd-t-info">
                        <img class="pd-ic--info" src="/assets/img/detail-ic-info.png" alt="" width="89" height="112">
                        Informasi Pesanan
                    </h2>
                </div>

                <dl class="pd-list">
                    <div class="pd-list__row">
                        <dt>Tanggal</dt>
                        <dd><?= e(substr($pesanan["tanggal_pesanan"], 0, 16)) ?></dd>
                    </div>
                    <div class="pd-list__row">
                        <dt>Status Pesanan</dt>
                        <!-- Teks status dari backend ditampilkan sebagai badge; warna menyesuaikan status ($badgePesanan di atas) -->
                        <dd><?= html_badge($badgePesanan, $pesanan["status_pesanan"]) ?></dd>
                    </div>
                    <div class="pd-list__row">
                        <dt>Tipe / DP</dt>
                        <dd>
                            <?= e($pesanan["tipe_pesanan"]) ?>
                            <?php if ((int)$pesanan["persentase_dp"] > 0): ?>
                                - DP <?= (int)$pesanan["persentase_dp"] ?>%
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="pd-list__row">
                        <dt>Total</dt>
                        <dd><?= e(format_rupiah((float)$pesanan["total_harga"])) ?></dd>
                    </div>
                </dl>
            </section>

            <!-- ---------- CARD: PEMBELI ---------- -->
            <section class="pd-card" aria-labelledby="pd-t-pembeli">
                <div class="pd-card__head">
                    <h2 class="pd-card__title" id="pd-t-pembeli">
                        <img class="pd-ic--pembeli" src="/assets/img/detail-ic-pembeli.png" alt="" width="89" height="112">
                        Pembeli
                    </h2>
                </div>

                <dl class="pd-list">
                    <div class="pd-list__row"><dt>Nama</dt><dd><?= e($pesanan["nama_mhs"] ?? $pesanan["username"]) ?></dd></div>
                    <div class="pd-list__row"><dt>NIM</dt><dd><?= e($pesanan["nim"] ?? "-") ?></dd></div>
                    <div class="pd-list__row"><dt>Email</dt><dd><?= e($pesanan["email"]) ?></dd></div>
                    <div class="pd-list__row"><dt>No. HP</dt><dd><?= e($pesanan["no_hp"] ?? "-") ?></dd></div>
                    <div class="pd-list__row"><dt>Alamat Kirim</dt><dd><?= nl2br(e($pengiriman["alamat"] ?? "-")) ?></dd></div>
                </dl>
            </section>
        </div>

        <!-- ================= BARIS 2 (1 kolom penuh): ITEM PESANAN ================= -->
        <section class="pd-card pd-card--item" aria-labelledby="pd-t-item">
            <h2 class="pd-card__title" id="pd-t-item">
                <img class="pd-ic--item" src="/assets/img/detail-ic-item.png" alt="" width="51" height="55">
                Item Pesanan
            </h2>

            <div class="pd-table" role="table" aria-label="Item pesanan">

                <!-- Baris judul kolom -->
                <div class="pd-table__head pd-cols--item" role="row">
                    <span role="columnheader">Produk</span>
                    <span role="columnheader">Harga Satuan</span>
                    <span role="columnheader">Jumlah</span>
                    <span role="columnheader">Subtotal</span>
                </div>

                <!-- LOOPING ITEM PESANAN MULAI DARI SINI (foreach $detail as $d; sebelumnya berada di dalam tag table) -->
                <?php foreach ($detail as $d): ?>
                    <?php $fotoItem = foto_thumb_url((string)($d["foto"] ?? "")); ?>
                    <div class="pd-row pd-row--item pd-cols--item" role="row">
                        <div class="pd-prod" role="cell">
                            <span class="pd-prod__img">
                                <?php if ($fotoItem !== ""): ?>
                                    <img src="<?= e($fotoItem) ?>" alt="<?= e($d["nama_merchandise"]) ?>" loading="lazy">
                                <?php else: ?>
                                    <!-- Belum ada foto di database: tampilkan ikon kardus sebagai placeholder -->
                                    <svg aria-hidden="true" focusable="false"><use href="#pd-ic-box"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="pd-prod__name"><?= e($d["nama_merchandise"]) ?></span>
                        </div>
                        <span role="cell" class="pd-num" data-label="Harga Satuan"><?= e(format_rupiah((float)$d["harga_satuan"])) ?></span>
                        <span role="cell" class="pd-num" data-label="Jumlah"><?= (int)$d["jumlah"] ?></span>
                        <span role="cell" class="pd-num" data-label="Subtotal"><?= e(format_rupiah((float)$d["subtotal"])) ?></span>
                    </div>
                <?php endforeach; ?>
                <!-- LOOPING ITEM PESANAN SELESAI DI SINI (endforeach) -->

                <!-- Baris TOTAL (sebelumnya baris terakhir <table>) -->
                <div class="pd-total" role="row">
                    <span class="pd-total__label" role="rowheader">Total</span>
                    <span class="pd-total__sep" aria-hidden="true"></span>
                    <span class="pd-total__value" role="cell"><?= e(format_rupiah((float)$pesanan["total_harga"])) ?></span>
                </div>
            </div>
        </section>

        <!-- ================= BARIS 3 (1 kolom penuh): RIWAYAT PEMBAYARAN ================= -->
        <section class="pd-card pd-card--bayar" aria-labelledby="pd-t-bayar">
            <h2 class="pd-card__title" id="pd-t-bayar">
                <img class="pd-ic--bayar" src="/assets/img/detail-ic-bayar.png" alt="" width="112" height="83">
                Riwayat Pembayaran
            </h2>

            <?php if (!$pembayaran): ?>
                <p class="pd-empty">Belum ada transaksi pembayaran.</p>
            <?php else: ?>
                <div class="pd-table" role="table" aria-label="Riwayat pembayaran">

                    <!-- Baris judul kolom -->
                    <div class="pd-table__head pd-cols--bayar" role="row">
                        <span role="columnheader">Waktu</span>
                        <span role="columnheader">Jenis</span>
                        <span role="columnheader">Metode</span>
                        <span role="columnheader">Jumlah</span>
                        <span role="columnheader">Status</span>
                        <span role="columnheader">Order ID</span>
                    </div>

                    <!-- LOOPING RIWAYAT PEMBAYARAN MULAI DARI SINI (foreach $pembayaran as $pb; sebelumnya berada di dalam tag table) -->
                    <?php foreach ($pembayaran as $pb): ?>
                        <div class="pd-row pd-row--bayar pd-cols--bayar" role="row">
                            <span role="cell" class="pd-num" data-label="Waktu"><?= e(substr($pb["tanggal_pembayaran"], 0, 16)) ?></span>
                            <span role="cell" data-label="Jenis"><?= e($pb["jenis_pembayaran"]) ?></span>
                            <span role="cell" data-label="Metode"><?= e($pb["metode_pembayaran"]) ?></span>
                            <span role="cell" class="pd-num" data-label="Jumlah">Rp <?= number_format((float)$pb["jumlah_bayar"], 0, ",", ".") ?></span>
                            <!-- Status pembayaran sebagai badge (Berhasil = hijau, Menunggu = oranye, Gagal = merah, dst.) -->
                            <span role="cell" data-label="Status"><?= html_badge($badgeBayar, $pb["status_bayar"]) ?></span>
                            <span role="cell" class="pd-orderid" data-label="Order ID"><?= e($pb["order_id"]) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <!-- LOOPING RIWAYAT PEMBAYARAN SELESAI DI SINI (endforeach) -->
                </div>
            <?php endif; ?>
        </section>

        <!-- ================= BARIS 4 (2 kolom): UBAH STATUS PESANAN + PENGIRIMAN ================= -->
        <div class="pd-grid pd-grid--forms">

            <!-- ---------- CARD: UBAH STATUS PESANAN ---------- -->
            <section class="pd-card pd-card--form" aria-labelledby="pd-t-status">
                <h2 class="pd-card__title" id="pd-t-status">
                    <img class="pd-ic--status" src="/assets/img/detail-ic-status.png" alt="" width="112" height="85">
                    Ubah Status Pesanan
                </h2>

                <?php if ($transisi): ?>
                    <!-- FORM UBAH STATUS: method, csrf_token, action, dan name="status_baru" sama dengan kode backend asli -->
                    <form method="POST" class="pd-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="status">

                        <label class="pd-label" for="pd-status-baru">Status baru</label>
                        <div class="pd-inline">
                            <div class="pd-inline__field">
                                <select class="pd-field" id="pd-status-baru" name="status_baru" required>
                                    <?php foreach ($transisi as $s): ?>
                                        <option value="<?= e($s) ?>"><?= e($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="pd-btn">
                                <img src="/assets/img/detail-ic-simpan.png" alt="" width="30" height="28">
                                Terapkan
                            </button>
                        </div>
                    </form>
                    <p class="pd-note">Transisi sah dari <b><?= e($pesanan["status_pesanan"]) ?></b>: <?= e(implode(", ", $transisi) ?: "tidak ada") ?>.</p>
                <?php else: ?>
                    <p class="pd-final"><i>Status <b><?= e($pesanan["status_pesanan"]) ?></b> adalah status akhir — tidak ada perubahan lagi.</i></p>
                <?php endif; ?>
            </section>

            <!-- ---------- CARD: PENGIRIMAN ---------- -->
            <section class="pd-card pd-card--form" aria-labelledby="pd-t-kirim">
                <h2 class="pd-card__title" id="pd-t-kirim">
                    <img class="pd-ic--kirim" src="/assets/img/detail-ic-kirim.png" alt="" width="49" height="42">
                    Pengiriman
                </h2>

                <?php if ($pengiriman !== false): ?>
                    <!-- FORM PENGIRIMAN: method, csrf_token, action, dan semua name input sama dengan kode backend asli -->
                    <form method="POST" class="pd-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="pengiriman">

                        <div class="pd-ship">
                            <div>
                                <label class="pd-label" for="pd-status-kirim">Status Pengiriman</label>
                                <select class="pd-field" id="pd-status-kirim" name="status_pengiriman">
                                    <?php foreach (["Belum Dikirim", "Dikirim", "Diterima"] as $s): ?>
                                        <option value="<?= e($s) ?>"
                                            <?= $pengiriman["status_pengiriman"] === $s ? "selected" : "" ?>>
                                            <?= e($s) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="pd-label" for="pd-tgl-kirim">Tanggal Pengiriman</label>
                                <input class="pd-field" type="datetime-local" id="pd-tgl-kirim" name="tanggal_pengiriman"
                                       value="<?= e(str_replace(" ", "T", substr($pengiriman["tanggal_pengiriman"] ?? "", 0, 16))) ?>">
                            </div>

                            <div class="pd-ship__resi">
                                <div class="pd-inline__field">
                                    <label class="pd-label" for="pd-resi">Bukti / No. Resi (Opsional)</label>
                                    <input class="pd-field" type="text" id="pd-resi" name="bukti_pengiriman" maxlength="255"
                                           placeholder="Masukkan No Resi"
                                           value="<?= e($pengiriman["bukti_pengiriman"] ?? "") ?>">
                                </div>
                                <button type="submit" class="pd-btn">
                                    <img src="/assets/img/detail-ic-simpan.png" alt="" width="30" height="28">
                                    Simpan Pengiriman
                                </button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="pd-final"><i>Baris pengiriman belum ada untuk pesanan ini.</i></p>
                <?php endif; ?>
            </section>
        </div>

    </div>
</main>

</body>
</html>
