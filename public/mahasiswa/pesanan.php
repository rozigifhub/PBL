<?php

/**
 * Detail pesanan milik mahasiswa (dipakai juga sebagai halaman
 * konfirmasi setelah checkout: pesanan.php?id=X&baru=1).
 *
 * Tampilan mengikuti desain Figma "Detail Pesanan" (CSS: assets/css/pesanan-detail.css).
 *
 * YANG TIDAK DIUBAH : session, require_mahasiswa(), proses refund (POST action=refund + csrf),
 *                     query $pembayaran / $pesanan / $terbayar / $sisa / $batasRefund / $bisaRefund,
 *                     kondisi $bisaBayar & $labelBayar, form refund (csrf_token, action, confirm),
 *                     link ke bayar.php, payment_url Midtrans, dan menu cepat di paling bawah.
 * YANG DITAMBAH     : (1) kolom m.foto pada query $detail (foto produk di kartu Item Pesanan)
 *                     (2) satu query kecil $tglBayar (waktu pembayaran berhasil, untuk timeline)
 *                     (3) variabel khusus tampilan: $badgePesanan, $badgeBayar, $langkahTimeline,
 *                         serta fungsi html_badge(), format_rupiah(), foto_url(), foto_thumb_url()
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/midtrans.php";
require_once __DIR__ . "/../../config/gambar.php";   // thumb_nama(): thumbnail foto produk di kartu Item Pesanan
require_mahasiswa();

$id   = (int)($_GET["id"] ?? 0);
$baru = isset($_GET["baru"]);

// Callback Midtrans mengirim order_id (format: PBL-00001-<timestamp>), bukan id.
// Bila parameter id tidak ada, ambil id pesanan dari situ.
if ($id === 0 && !empty($_GET["order_id"])
    && preg_match('/^PBL-(\d+)-/', (string)$_GET["order_id"], $m)) {
    $id = (int)$m[1];
}

// Pilihan alasan refund; di bawahnya selalu ada "Lainnya" (user menulis sendiri)
const ALASAN_REFUND = [
    "Salah memilih ukuran/varian",
    "Tidak jadi membeli",
    "Ingin mengganti produk",
    "Menemukan harga lebih murah",
    "Pembayaran/pesanan ganda",
    "Pengiriman terlalu lama",
];

// ---------- Refund ke dompet (maks 24 jam sejak pembayaran pertama) ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "refund") {

    if (!csrf_validate()) {
        set_flash("gagal", "Sesi tidak valid. Silakan coba lagi.");
        redirect("/mahasiswa/pesanan.php?id=" . $id);
    }

    $pilihan = (string)($_POST["alasan_pilihan"] ?? "");
    if ($pilihan === "Lainnya") {
        $lain   = trim((string)($_POST["alasan_lain"] ?? ""));
        $alasan = "Lainnya: " . $lain;
        $valid  = mb_strlen($lain) >= 5 && mb_strlen($lain) <= 300;
    } else {
        $alasan = $pilihan;
        $valid  = in_array($pilihan, ALASAN_REFUND, true);
    }
    if (!$valid) {
        set_flash("gagal", "Pilih alasan refund. Untuk \"Lainnya\", tulis alasan Anda (5-300 karakter).");
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

        // Alasan refund disimpan dalam transaksi yang sama dengan refund-nya
        $stmt = $pdo->prepare("
            INSERT INTO refund_pesanan (id_pesanan, id_akun, alasan, jumlah)
            VALUES (:id, :akun, :alasan, :jumlah)
            ON CONFLICT (id_pesanan) DO NOTHING
        ");
        $stmt->execute([
            ":id"     => $id,
            ":akun"   => $_SESSION["id_akun"],
            ":alasan" => $alasan,
            ":jumlah" => $hasil["out_jumlah"],
        ]);

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
        SELECT d.jumlah, d.harga_satuan, d.subtotal, m.nama_merchandise, m.foto
        FROM detail_pesanan d
        JOIN merchandise m ON m.id_merchandise = d.id_merchandise
        WHERE d.id_pesanan = :id
        ORDER BY d.id_detail
    ");
    $stmt->execute([":id" => $id]);
    $detail = $stmt->fetchAll();
}

// ---------- Tampilan saja (tidak memengaruhi data) ----------

// Badge status pesanan: status => [warna, ikon]. Warna didefinisikan di pesanan-detail.css (.mp-badge--*).
// Sama dengan pemetaan di halaman admin & riwayat supaya warna status konsisten antar halaman.
$badgePesanan = [
    "Menunggu Pembayaran" => ["wait",    "mp-ic-clock"],
    "Menunggu DP"         => ["wait",    "mp-ic-clock"],
    "Menunggu Pelunasan"  => ["wait",    "mp-ic-clock"],
    "DP Lunas"            => ["done",    "mp-ic-check"],
    "Lunas"               => ["done",    "mp-ic-check"],
    "Diproses"            => ["process", "mp-ic-gear"],
    "Dikirim"             => ["ship",    "mp-ic-truck"],
    "Selesai"             => ["done",    "mp-ic-check"],
    "Dibatalkan"          => ["off",     "mp-ic-x"],
];

// Badge status pembayaran (nilai dari tabel pembayaran / Midtrans).
$badgeBayar = [
    "Menunggu"    => ["wait", "mp-ic-clock"],
    "Berhasil"    => ["done", "mp-ic-check"],
    "Gagal"       => ["fail", "mp-ic-x"],
    "Kedaluwarsa" => ["off",  "mp-ic-clock"],
];

/** Badge berikon. Status yang tidak dikenal (mis. "Belum ada transaksi") tetap tampil abu-abu tanpa ikon. */
if (!function_exists("html_badge")) {
    function html_badge(array $peta, ?string $teks): string
    {
        if ($teks === null || $teks === "") {
            return '<span class="mp-badge mp-badge--off">-</span>';
        }
        [$warna, $ikon] = $peta[$teks] ?? ["off", null];
        $svg = $ikon !== null
            ? '<svg aria-hidden="true" focusable="false"><use href="#' . $ikon . '"/></svg>'
            : "";
        $kecil = strlen($teks) > 11 ? " mp-badge--sm" : "";   // teks panjang dikecilkan agar tetap satu baris
        return '<span class="mp-badge mp-badge--' . $warna . $kecil . '">' . $svg . e($teks) . '</span>';
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

// ---------- Timeline "Status Pesanan" ----------
// Dibangun dari status_pesanan di backend. Langkah yang sudah dilalui = centang hijau ("done"),
// langkah yang sedang ditunggu = cincin oranye ("now"), sisanya cincin krem ("todo").
// Pesanan "Dibatalkan" berhenti di langkah 2 dengan penanda merah ("off").
$langkahTimeline = [];
if ($pesanan !== false) {
    // Waktu pembayaran berhasil terakhir (untuk tanggal di langkah "Pembayaran Dikonfirmasi")
    $stmt = $pdo->prepare("
        SELECT MAX(COALESCE(dibayar_pada, tanggal_pembayaran))
        FROM pembayaran
        WHERE id_pesanan = :id AND status_bayar = 'Berhasil'
    ");
    $stmt->execute([":id" => $id]);
    $tglBayar = $stmt->fetchColumn() ?: null;

    $statusKini = $pesanan["status_pesanan"];
    $dibatalkan = $statusKini === "Dibatalkan";
    $sebagianDp = in_array($statusKini, ["DP Lunas", "Menunggu Pelunasan"], true);   // DP sudah masuk, pelunasan belum

    // Jumlah langkah (dari atas) yang sudah hijau untuk tiap status
    $langkahHijau = [
        "Menunggu Pembayaran" => 1,
        "Menunggu DP"         => 1,
        "DP Lunas"            => 1,
        "Menunggu Pelunasan"  => 1,
        "Lunas"               => 2,
        "Diproses"            => 3,
        "Dikirim"             => 4,
        "Selesai"             => 5,
        "Dibatalkan"          => 1,
    ][$statusKini] ?? 1;

    // [judul, tanggal (kosong bila backend belum mencatat), teks bila sudah dilalui, teks bila masih ditunggu]
    $daftarLangkah = [
        ["Pesanan Dibuat",          substr((string)$pesanan["tanggal_pesanan"], 0, 16), "Pesanan diterima", "Pesanan diterima"],
        ["Pembayaran Dikonfirmasi", $tglBayar ? substr((string)$tglBayar, 0, 16) : "",  "Pembayaran diterima", "Menunggu pembayaran"],
        ["Pesanan Diproses",        "",                                                  "Pesanan sudah diproses admin", "Menunggu diproses admin"],
        ["Pesanan Dikirim",         !empty($pesanan["tanggal_pengiriman"]) ? substr((string)$pesanan["tanggal_pengiriman"], 0, 16) : "", "Pesanan sudah dikirim", "Belum dikirim"],
        ["Pesanan Selesai",         "",                                                  "Pesanan telah diterima", "Menunggu pesanan diterima"],
    ];

    foreach ($daftarLangkah as $i => [$judul, $tgl, $teksSelesai, $teksMenunggu]) {
        $nomor = $i + 1;

        if ($dibatalkan && $nomor === 2) {
            $langkahTimeline[] = ["judul" => "Pesanan Dibatalkan", "status" => "off", "label" => "dibatalkan", "teks" => "Pesanan tidak dilanjutkan", "garis" => false];
            break;
        }

        if ($nomor <= $langkahHijau) {
            $status = "done";
            $label  = "sudah dilalui";
            $teks   = $tgl !== "" ? $tgl : $teksSelesai;
        } else {
            $status = $nomor === $langkahHijau + 1 ? "now" : "todo";
            $label  = $status === "now" ? "sedang ditunggu" : "belum dilalui";
            $teks   = ($status === "now" && $nomor === 2 && $sebagianDp) ? "DP diterima, menunggu pelunasan" : $teksMenunggu;
        }

        $langkahTimeline[] = ["judul" => $judul, "status" => $status, "label" => $label, "teks" => $teks, "garis" => false];
    }

    // Garis penghubung ke langkah berikutnya hijau hanya bila langkah berikutnya sudah dilalui
    foreach ($langkahTimeline as $i => $langkah) {
        $berikut = $langkahTimeline[$i + 1] ?? null;
        $langkahTimeline[$i]["garis"] = $berikut !== null && $berikut["status"] === "done";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pesanan #<?= $id ?> - Merchandise Kampus</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/menu-cepat.css?v=<?= @filemtime(__DIR__ . "/../assets/css/menu-cepat.css") ?>">
    <link rel="stylesheet" href="/assets/css/pesanan-detail.css?v=<?= @filemtime(__DIR__ . "/../assets/css/pesanan-detail.css") ?>">
</head>
<body>
<?php
$mhsActive = "pesanan";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>

<!-- Ikon (dipakai berulang lewat <use>): badge status, penanda timeline, ikon kartu Aksi -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <!-- Ikon badge status -->
    <symbol id="mp-ic-check" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10" fill="currentColor"/>
        <path d="m7.6 12.4 3 3 5.8-6.2" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="mp-ic-clock" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10" fill="currentColor"/>
        <path d="M12 7v5.2l3.2 1.9" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="mp-ic-x" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10" fill="currentColor"/>
        <path d="m9 9 6 6M15 9l-6 6" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="mp-ic-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 6.5h11.5v10H2z"/>
        <path d="M13.5 10h4.2l3.3 3.4v3.1h-7.5"/>
        <circle cx="7" cy="18" r="2"/>
        <circle cx="17.5" cy="18" r="2"/>
    </symbol>
    <symbol id="mp-ic-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </symbol>
    <!-- Ikon placeholder bila produk belum punya foto -->
    <symbol id="mp-ic-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2.8 20 7.2v9.6L12 21.2 4 16.8V7.2z"/>
        <path d="M4.3 7.4 12 11.8l7.7-4.4M12 11.8v9.2"/>
    </symbol>
    <!-- Penanda timeline: done = centang hijau, now = cincin oranye (sedang ditunggu), todo = cincin krem, off = dibatalkan -->
    <symbol id="mp-tl-done" viewBox="0 0 71 71">
        <circle cx="35.5" cy="35.5" r="35.5" fill="#46DE8F"/>
        <path d="M20.5 36.5 31 47 51 25" fill="none" stroke="#FFF6E0" stroke-width="6.5" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="mp-tl-now" viewBox="0 0 71 71">
        <circle cx="35.5" cy="35.5" r="32.5" fill="#FFF6E0" stroke="#FF9F1C" stroke-width="6"/>
        <circle cx="35.5" cy="35.5" r="10" fill="#FF9F1C"/>
    </symbol>
    <symbol id="mp-tl-todo" viewBox="0 0 71 71">
        <circle cx="35.5" cy="35.5" r="32.5" fill="#FFF6E0" stroke="#DCCDA6" stroke-width="6"/>
    </symbol>
    <symbol id="mp-tl-off" viewBox="0 0 71 71">
        <circle cx="35.5" cy="35.5" r="35.5" fill="#D6453A"/>
        <path d="m25 25 21 21M46 25 25 46" fill="none" stroke="#FFF6E0" stroke-width="6.5" stroke-linecap="round"/>
    </symbol>
    <!-- Ikon kartu Aksi Pesanan -->
    <symbol id="mp-ic-aksi" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
        <path d="M2.5 10h19M6.5 15h4"/>
    </symbol>
</svg>

<main class="mp">
    <div class="mp__inner">

    <?php if ($pesanan === false): ?>

        <!-- PESANAN TIDAK DITEMUKAN (logika asli dipertahankan) -->
        <section class="mp-card mp-kosong">
            <h1>Pesanan tidak ditemukan</h1>
            <p><a class="mp-btn mp-btn--lanjut" href="/mahasiswa/katalog.php">&larr; Kembali ke Katalog</a></p>
        </section>

    <?php else: ?>

        <!-- ================= KEPALA HALAMAN: breadcrumb + ikon + judul ================= -->
        <header class="mp-head">
            <nav class="mp-crumb" aria-label="Breadcrumb">
                <img src="/assets/img/icon-home.png" alt="" width="43" height="43">
                <!-- Teks breadcrumb mengikuti Figma. "Kelola Pesanan" mengarah ke Riwayat Pesanan (daftar pesanan mahasiswa) -->
                <span>
                    <a href="/mahasiswa/dashboard.php">Dashboard</a> /
                    <a href="/mahasiswa/riwayat.php">Kelola Pesanan</a> /
                    <a href="/mahasiswa/pesanan.php?id=<?= (int)$pesanan["id_pesanan"] ?>" aria-current="page">Detail Pesanan</a>
                </span>
            </nav>
            <div class="mp-title">
                <span class="mp-title__icon" aria-hidden="true"><img src="/assets/img/icon-package.png" alt="" width="128" height="128"></span>
                <div>
                    <!-- ID PESANAN dari backend ($pesanan["id_pesanan"]) -->
                    <h1>Pesanan #<?= (int)$pesanan["id_pesanan"] ?></h1>
                    <p>Detail informasi pesanan dan pengelolaan status pengirim</p>
                </div>
            </div>
        </header>

        <!-- ================= PESAN DARI BACKEND (flash & pesanan baru) ================= -->
        <?php if ($flash): ?>
            <div class="alert <?= $flash["type"] === "sukses" ? "alert--success" : "alert--error" ?>" role="<?= $flash["type"] === "sukses" ? "status" : "alert" ?>">
                <b><?= e($flash["msg"]) ?></b>
            </div>
        <?php endif; ?>

        <?php if ($baru): ?>
            <div class="alert alert--success" role="status"><b>✅ Pesanan berhasil dibuat! Simpan nomor pesanan di bawah ini.</b></div>
        <?php endif; ?>

        <!-- ================= BARIS 1 (2 kolom): INFORMASI PESANAN + STATUS PESANAN ================= -->
        <div class="mp-grid">

            <!-- ---------- CARD KIRI: INFORMASI PESANAN ---------- -->
            <section class="mp-card mp-card--info" aria-labelledby="mp-t-info">
                <div class="mp-card__head">
                    <h2 class="mp-card__title" id="mp-t-info">
                        <img class="mp-ic--info" src="/assets/img/detail-mhs-ic-info.png" alt="" width="63" height="65">
                        Informasi Pesanan
                    </h2>
                </div>

                <!-- Baris data utama (sesuai Figma). Titik dua sejajar karena kolom label berlebar tetap. -->
                <dl class="mp-list mp-list--main">
                    <div class="mp-list__row">
                        <dt>Tanggal</dt>
                        <dd><?= e(substr($pesanan["tanggal_pesanan"], 0, 16)) ?></dd>
                    </div>
                    <div class="mp-list__row">
                        <dt>Status Pembayaran</dt>
                        <!-- Teks status pembayaran dari backend ditampilkan sebagai badge; warna dinamis ($badgeBayar di atas) -->
                        <dd><?= html_badge($badgeBayar, $pembayaran["status_bayar"] ?? "Belum ada transaksi") ?></dd>
                    </div>
                    <div class="mp-list__row">
                        <dt>Status Pesanan</dt>
                        <!-- Teks status pesanan dari backend ditampilkan sebagai badge; warna dinamis ($badgePesanan di atas) -->
                        <dd><?= html_badge($badgePesanan, $pesanan["status_pesanan"]) ?></dd>
                    </div>
                    <div class="mp-list__row">
                        <dt>Tipe</dt>
                        <dd>
                            <?= e($pesanan["tipe_pesanan"]) ?>
                            <?php if ((int)$pesanan["persentase_dp"] > 0): ?>
                                <small class="mp-dp">DP <?= (int)$pesanan["persentase_dp"] ?>% (<?= e(format_rupiah((float)$pesanan["total_harga"] * (int)$pesanan["persentase_dp"] / 100)) ?>)</small>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="mp-list__row">
                        <dt>Total</dt>
                        <dd><?= e(format_rupiah((float)$pesanan["total_harga"])) ?></dd>
                    </div>
                    <div class="mp-list__row">
                        <dt>Alamat Pengiriman</dt>
                        <dd><?= nl2br(e($pesanan["alamat"] ?? "-")) ?></dd>
                    </div>
                </dl>

                <!-- Baris tambahan dari backend (tidak ada di Figma, tetap ditampilkan agar data tidak hilang): -->
                <dl class="mp-list mp-list--extra">
                    <div class="mp-list__row">
                        <dt>Sudah Dibayar</dt>
                        <dd><?= e(format_rupiah($terbayar)) ?></dd>
                    </div>
                    <?php if ($sisa > 0): ?>
                    <div class="mp-list__row">
                        <dt>Sisa Tagihan</dt>
                        <dd><b><?= e(format_rupiah($sisa)) ?></b></dd>
                    </div>
                    <?php endif; ?>
                    <div class="mp-list__row">
                        <dt>Status Pengiriman</dt>
                        <dd><?= e($pesanan["status_pengiriman"] ?? "Belum Dikirim") ?></dd>
                    </div>
                </dl>
            </section>

            <!-- ---------- CARD KANAN: STATUS PESANAN (TIMELINE) ---------- -->
            <section class="mp-card mp-card--status" aria-labelledby="mp-t-status">
                <div class="mp-card__head">
                    <h2 class="mp-card__title" id="mp-t-status">
                        <img class="mp-ic--status" src="/assets/img/detail-mhs-ic-status.png" alt="" width="83" height="83">
                        Status Pesanan
                    </h2>
                </div>

                <!-- Timeline dibangun dari status_pesanan backend lewat $langkahTimeline (lihat blok PHP di atas file).
                     Kelas mp-step--done (hijau) / --now (oranye) / --todo (krem) / --off (merah) ditentukan di sana. -->
                <ol class="mp-steps<?= count($langkahTimeline) < 5 ? " mp-steps--ringkas" : "" ?>">
                    <!-- LOOPING TIMELINE MULAI DARI SINI (foreach $langkahTimeline as $l) -->
                    <?php foreach ($langkahTimeline as $l): ?>
                        <li class="mp-step mp-step--<?= e($l["status"]) ?><?= $l["garis"] ? " mp-step--garis" : "" ?>">
                            <span class="mp-step__mark" aria-hidden="true"><svg viewBox="0 0 71 71" focusable="false"><use href="#mp-tl-<?= e($l["status"]) ?>"/></svg></span>
                            <div class="mp-step__body">
                                <p class="mp-step__title"><?= e($l["judul"]) ?><span class="mp-sr"> (<?= e($l["label"]) ?>)</span></p>
                                <p class="mp-step__meta"><?= e($l["teks"]) ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <!-- LOOPING TIMELINE SELESAI DI SINI (endforeach) -->
                </ol>
            </section>
        </div>

        <!-- ================= BARIS 2 (1 kolom penuh): ITEM PESANAN ================= -->
        <section class="mp-card mp-card--item" aria-labelledby="mp-t-item">
            <h2 class="mp-card__title" id="mp-t-item">
                <img class="mp-ic--item" src="/assets/img/detail-mhs-ic-item.png" alt="" width="65" height="65">
                Item Pesanan
            </h2>

            <div class="mp-table" role="table" aria-label="Item pesanan">

                <!-- Baris judul kolom (sebelumnya <th>) -->
                <div class="mp-table__head mp-cols" role="row">
                    <span role="columnheader">Produk</span>
                    <span role="columnheader">Harga Satuan</span>
                    <span role="columnheader">Jumlah</span>
                    <span role="columnheader">Subtotal</span>
                </div>

                <!-- LOOPING ITEM PESANAN MULAI DARI SINI (foreach $detail as $d; sebelumnya berada di dalam tag table) -->
                <?php foreach ($detail as $d): ?>
                    <?php $fotoItem = foto_thumb_url((string)($d["foto"] ?? "")); ?>
                    <div class="mp-row mp-cols" role="row">
                        <div class="mp-prod" role="cell">
                            <span class="mp-prod__img">
                                <?php if ($fotoItem !== ""): ?>
                                    <img src="<?= e($fotoItem) ?>" alt="<?= e($d["nama_merchandise"]) ?>" loading="lazy">
                                <?php else: ?>
                                    <!-- Belum ada foto di database: tampilkan ikon kardus sebagai placeholder -->
                                    <svg aria-hidden="true" focusable="false"><use href="#mp-ic-box"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="mp-prod__name"><?= e($d["nama_merchandise"]) ?></span>
                        </div>
                        <span role="cell" class="mp-num" data-label="Harga Satuan"><?= e(format_rupiah((float)$d["harga_satuan"])) ?></span>
                        <span role="cell" class="mp-num" data-label="Jumlah"><?= (int)$d["jumlah"] ?></span>
                        <span role="cell" class="mp-num" data-label="Subtotal"><?= e(format_rupiah((float)$d["subtotal"])) ?></span>
                    </div>
                <?php endforeach; ?>
                <!-- LOOPING ITEM PESANAN SELESAI DI SINI (endforeach) -->

                <!-- Baris TOTAL (sebelumnya baris terakhir <table>) -->
                <div class="mp-total" role="row">
                    <span class="mp-total__label" role="rowheader">Total</span>
                    <span class="mp-total__sep" aria-hidden="true"></span>
                    <span class="mp-total__value" role="cell"><?= e(format_rupiah((float)$pesanan["total_harga"])) ?></span>
                </div>
            </div>
        </section>

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

        <!-- ================= BARIS 3 (1 kolom penuh): AKSI PESANAN =================
             Kartu memanjang di bawah Item Pesanan dan di atas menu cepat (Lihat Katalog / Dashboard / ...).
             Seluruh kondisi if/else di bawah adalah logika asli backend, jangan dihapus. -->
        <section class="mp-card mp-card--aksi" aria-labelledby="mp-t-aksi">
            <h2 class="mp-aksi__title" id="mp-t-aksi">
                <svg aria-hidden="true" focusable="false"><use href="#mp-ic-aksi"/></svg>
                Aksi Pesanan
            </h2>

            <!-- AREA TOMBOL BAYAR (JANGAN HAPUS LOGIKA PHP): tampil bila $bisaBayar; link ke bayar.php + payment_url Midtrans -->
            <?php if ($bisaBayar): ?>
                <div class="mp-aksi__blok">
                    <div class="mp-aksi__teks">
                        <p class="mp-aksi__lead">Pembayaran untuk pesanan ini belum selesai.</p>
                        <p class="mp-aksi__note">Pembayaran online (Midtrans) — selesaikan sebelum tautan kedaluwarsa.</p>
                    </div>
                    <p class="mp-aksi__tombol">
                        <!-- Tombol utama: label dari $labelBayar (Bayar Sekarang / Bayar DP Sekarang / Bayar Pelunasan) -->
                        <a class="mp-btn mp-btn--bayar" href="/mahasiswa/bayar.php?id=<?= (int)$pesanan["id_pesanan"] ?>"><?= e($labelBayar) ?> &rarr;</a>
                        <?php if (($pembayaran["status_bayar"] ?? "") === "Menunggu" && !empty($pembayaran["payment_url"])): ?>
                            <a class="mp-btn mp-btn--lanjut" href="<?= e($pembayaran["payment_url"]) ?>">Lanjutkan pembayaran sebelumnya</a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php elseif ($statusPesanan === "Lunas"): ?>
                <div class="mp-aksi__blok">
                    <p class="mp-aksi__lead"><b>Pesanan sudah lunas.</b> Menunggu diproses oleh admin.</p>
                </div>
            <?php endif; ?>

            <!-- AREA TOMBOL BATALKAN & REFUND (JANGAN HAPUS LOGIKA PHP): form POST action=refund + csrf_token + konfirmasi JS -->
            <?php if ($bisaRefund): ?>
                <div class="mp-aksi__blok">
                    <div class="mp-aksi__teks">
                        <h3 class="mp-aksi__judul">Batalkan &amp; Refund</h3>
                        <p class="mp-aksi__lead">
                            Tidak jadi membeli? Pesanan bisa dibatalkan dan seluruh pembayaran
                            (<?= e(format_rupiah($terbayar)) ?>) dikembalikan ke
                            <a href="/mahasiswa/dompet.php">dompet</a> Anda.
                            Batas refund: <b><?= e($batasRefund->format("d M Y H:i")) ?></b>.
                        </p>
                    </div>
                    <form class="mp-aksi__tombol" method="POST" onsubmit="return confirm('Batalkan pesanan ini dan refund ke dompet?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="refund">
                        <label class="mp-aksi__alasan-label" for="alasan-pilihan">Alasan refund <span aria-hidden="true">*</span></label>
                        <select class="mp-aksi__alasan" id="alasan-pilihan" name="alasan_pilihan" required
                                onchange="document.getElementById('alasan-lain').hidden = this.value !== 'Lainnya'; document.getElementById('alasan-lain').required = this.value === 'Lainnya';">
                            <option value="">-- pilih alasan --</option>
                            <?php foreach (ALASAN_REFUND as $opsi): ?>
                                <option value="<?= e($opsi) ?>"><?= e($opsi) ?></option>
                            <?php endforeach; ?>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                        <textarea class="mp-aksi__alasan" id="alasan-lain" name="alasan_lain" rows="3" minlength="5" maxlength="300" hidden
                                  placeholder="Tulis alasan Anda (minimal 5 karakter)"></textarea>
                        <button class="mp-btn mp-btn--refund" type="submit">Batalkan &amp; Refund ke Dompet</button>
                    </form>
                </div>
            <?php elseif ($batasRefund !== null): ?>
                <div class="mp-aksi__blok">
                    <p class="mp-aksi__note">Batas refund otomatis (24 jam sejak pembayaran) sudah lewat. Hubungi customer service bila ingin membatalkan.</p>
                </div>
            <?php endif; ?>

            <!-- Tidak ada tindakan untuk status ini (mis. Diproses, Dikirim, Selesai, Dibatalkan) -->
            <?php if (!$bisaBayar && $statusPesanan !== "Lunas" && !$bisaRefund && $batasRefund === null): ?>
                <div class="mp-aksi__blok">
                    <p class="mp-aksi__lead">Tidak ada tindakan yang perlu Anda lakukan untuk pesanan ini.</p>
                </div>
            <?php endif; ?>
        </section>

    <?php endif; ?>

    </div>

    <!-- Menu cepat (paling bawah): sama dengan dashboard -->
    <nav class="menu-cepat" aria-label="Menu cepat">
        <a class="menu-cepat__main" href="/mahasiswa/katalog.php">Lihat Katalog</a>
        <a href="/mahasiswa/dashboard.php">Lihat Dashboard</a>
        <a href="/mahasiswa/profil.php">Kelola Profil</a>
        <a class="menu-cepat__out" href="/logout.php">Logout</a>
    </nav>

</main>

</body>
</html>
