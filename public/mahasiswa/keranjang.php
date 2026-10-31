<?php

/**
 * Keranjang belanja mahasiswa (berbasis session) — tampilan sesuai desain UI/UX.
 *
 * Struktur $_SESSION["keranjang"]:
 *   [id_merchandise => ["jumlah" => int], ...]
 *
 * Harga & nama SELALU diambil dari database saat ditampilkan —
 * snapshot harga (harga_satuan) baru dikunci saat checkout (fase 3B).
 *
 * Aksi POST yang dilayani (semuanya wajib csrf_token):
 *   tambah          dari katalog / detail produk                       (tetap)
 *   update          ubah jumlah satu item; tombol - / + di halaman ini
 *                   memakai AJAX (lihat assets/js/keranjang.js)        (tetap)
 *   hapus           hapus satu item                                    (tetap)
 *   kosongkan       kosongkan keranjang (tidak ada tombolnya di desain,
 *                   aksinya tetap dilayani)                            (tetap)
 *   hapus_terpilih  hapus beberapa item sekaligus (checkbox)           (BARU)
 *
 * Ringkasan Belanja mencerminkan SELURUH isi keranjang, sama seperti
 * checkout.php yang memproses semua item di keranjang. Checkbox di daftar
 * dipakai untuk "Hapus Yang Dipilih", bukan untuk memilih item yang dibayar.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_mahasiswa();

/**
 * Estimasi ongkos kirim (Rupiah) pada Ringkasan Belanja. Isi 0 untuk menyembunyikan baris ongkir.
 *
 * PERHATIAN: checkout.php BELUM menambahkan ongkir ke pesanan.total_harga maupun
 * tagihan Midtrans. Selama itu belum diubah, angka ini hanya tampilan (estimasi).
 */
const ONGKIR_ESTIMASI = 15000;

/** URL foto produk (absolut dari root web); string kosong jika file tidak ada. */
function foto_url(string $foto): string
{
    $nama = basename($foto);
    return is_file(__DIR__ . "/../uploads/merchandise/" . $nama)
        ? "/uploads/merchandise/" . $nama
        : "";
}

/** Format Rupiah mengikuti desain: "Rp. 100.000". */
function rupiah(float $angka): string
{
    return "Rp. " . number_format($angka, 0, ",", ".");
}

/** Jawab permintaan AJAX (dari keranjang.js) dengan JSON lalu berhenti. */
function json_keluar(array $data, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$isAjax = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";
$error  = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
        if ($isAjax) {
            json_keluar(["ok" => false, "message" => $error], 403);
        }
    } else {
        $action    = $_POST["action"] ?? "";
        $id        = (int)($_POST["id_merchandise"] ?? 0);
        $keranjang = $_SESSION["keranjang"] ?? [];

        // Hapus satu item
        if ($action === "hapus") {
            unset($keranjang[$id]);
            $_SESSION["keranjang"] = $keranjang;
            set_flash("sukses", "Item dihapus dari keranjang.");
            redirect("/mahasiswa/keranjang.php");
        }

        // Hapus beberapa item sekaligus (checkbox "Pilih Semua" / "Hapus Yang Dipilih")
        if ($action === "hapus_terpilih") {
            $pilihan = $_POST["ids"] ?? [];
            $dihapus = 0;

            if (is_array($pilihan)) {
                foreach ($pilihan as $pid) {
                    $pid = (int)$pid;
                    if (isset($keranjang[$pid])) {
                        unset($keranjang[$pid]);
                        $dihapus++;
                    }
                }
            }

            if ($dihapus > 0) {
                $_SESSION["keranjang"] = $keranjang;
                set_flash("sukses", "{$dihapus} item dihapus dari keranjang.");
            } else {
                set_flash("gagal", "Belum ada produk yang dipilih.");
            }
            redirect("/mahasiswa/keranjang.php");
        }

        // Kosongkan seluruh keranjang
        if ($action === "kosongkan") {
            unset($_SESSION["keranjang"]);
            set_flash("sukses", "Keranjang dikosongkan.");
            redirect("/mahasiswa/keranjang.php");
        }

        // Tambah item (dari halaman katalog / detail produk)
        if ($action === "tambah") {
            $jumlah = max(1, (int)($_POST["jumlah"] ?? 1));

            // Ambil stok terkini dari database
            $stmt = $pdo->prepare("SELECT stok FROM merchandise WHERE id_merchandise = :id");
            $stmt->execute([":id" => $id]);
            $stok = $stmt->fetchColumn();

            if ($stok === false || (int)$stok < 1) {
                set_flash("gagal", "Produk tidak tersedia.");
            } else {
                $jumlahLama = (int)($keranjang[$id]["jumlah"] ?? 0);
                $jumlahBaru = min($jumlahLama + $jumlah, (int)$stok);
                $keranjang[$id] = ["jumlah" => $jumlahBaru];
                $_SESSION["keranjang"] = $keranjang;

                if ($jumlahBaru < $jumlahLama + $jumlah) {
                    set_flash("gagal", "Stok tidak cukup — jumlah di keranjang disesuaikan menjadi {$jumlahBaru}.");
                } else {
                    set_flash("sukses", "Produk ditambahkan ke keranjang.");
                }
            }
            redirect("/mahasiswa/keranjang.php");
        }

        // Perbarui jumlah satu item (dengan clamp ke stok terkini).
        // Permintaan biasa -> flash + redirect (seperti semula).
        // Permintaan AJAX  -> JSON, tanpa flash, supaya halaman tidak dimuat ulang.
        if ($action === "update") {
            $jumlah = (int)($_POST["jumlah"] ?? 1);

            $stmt = $pdo->prepare("SELECT stok FROM merchandise WHERE id_merchandise = :id");
            $stmt->execute([":id" => $id]);
            $stok = $stmt->fetchColumn();

            $tipe        = "sukses";
            $jumlahAkhir = 0; // 0 = item sudah tidak ada di keranjang

            if ($stok === false) {
                unset($keranjang[$id]);
                $tipe  = "gagal";
                $pesan = "Produk sudah tidak tersedia dan dihapus dari keranjang.";
            } elseif (!isset($keranjang[$id])) {
                $tipe  = "gagal";
                $pesan = "Item tidak ditemukan di keranjang.";
            } elseif ($jumlah < 1) {
                unset($keranjang[$id]);
                $pesan = "Item dihapus dari keranjang.";
            } elseif ((int)$stok < 1) {
                $tipe        = "gagal";
                $pesan       = "Stok produk habis. Hapus item ini dari keranjang.";
                $jumlahAkhir = max(1, (int)($keranjang[$id]["jumlah"] ?? 1));
            } elseif ($jumlah > (int)$stok) {
                $keranjang[$id]["jumlah"] = (int)$stok;
                $tipe        = "gagal";
                $pesan       = "Stok tidak cukup — jumlah disesuaikan menjadi {$stok}.";
                $jumlahAkhir = (int)$stok;
            } else {
                $keranjang[$id]["jumlah"] = $jumlah;
                $pesan       = "Jumlah diperbarui.";
                $jumlahAkhir = $jumlah;
            }
            $_SESSION["keranjang"] = $keranjang;

            if ($isAjax) {
                json_keluar([
                    "ok"      => true,
                    "type"    => $tipe,
                    "message" => $pesan,
                    "jumlah"  => $jumlahAkhir,
                    "stok"    => $stok === false ? 0 : (int)$stok,
                    "removed" => $jumlahAkhir === 0,
                ]);
            }

            set_flash($tipe, $pesan);
            redirect("/mahasiswa/keranjang.php");
        }
    }
}

// ---------- Susun data keranjang untuk ditampilkan ----------

$keranjang = $_SESSION["keranjang"] ?? [];
$produkDb  = [];

// Satu query untuk seluruh isi keranjang (sebelumnya satu query per item)
if ($keranjang) {
    $ids         = array_map("intval", array_keys($keranjang));
    $placeholder = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare("
        SELECT m.id_merchandise, m.nama_merchandise, m.ukuran, m.harga, m.stok, m.foto, k.nama_kategori
        FROM merchandise m
        JOIN kategori_merchandise k ON k.id_kategori = m.id_kategori
        WHERE m.id_merchandise IN ($placeholder)
    ");
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $row) {
        $produkDb[(int)$row["id_merchandise"]] = $row;
    }
}

$items    = [];
$subtotal = 0.0;

foreach ($keranjang as $id => $item) {
    $p = $produkDb[(int)$id] ?? null;

    if ($p === null) {
        // Produk sudah dihapus admin — buang dari keranjang
        unset($keranjang[$id]);
        continue;
    }

    $jumlah     = max(1, (int)($item["jumlah"] ?? 1));
    $stokKurang = $jumlah > (int)$p["stok"];
    $subItem    = (float)$p["harga"] * $jumlah;

    $items[] = [
        "id"          => (int)$id,
        "nama"        => $p["nama_merchandise"],
        "kategori"    => $p["nama_kategori"],
        "ukuran"      => trim((string)$p["ukuran"]),
        "harga"       => (float)$p["harga"],
        "stok"        => (int)$p["stok"],
        "foto"        => (string)($p["foto"] ?? ""),
        "jumlah"      => $jumlah,
        "subtotal"    => $subItem,
        "stok_kurang" => $stokKurang,
    ];
    $subtotal += $subItem;
}
$_SESSION["keranjang"] = $keranjang; // simpan hasil pembersihan otomatis

$jumlahProduk  = count($items);
$ongkir        = $items ? (float)ONGKIR_ESTIMASI : 0.0;
$totalBayar    = $subtotal + $ongkir;
$adaStokKurang = false;
foreach ($items as $it) {
    if ($it["stok_kurang"]) {
        $adaStokKurang = true;
        break;
    }
}

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keranjang Belanja - Merchandise Kampus</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/../assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="/assets/css/keranjang.css?v=<?= @filemtime(__DIR__ . "/../assets/css/keranjang.css") ?>">
    <script src="/assets/js/keranjang.js?v=<?= @filemtime(__DIR__ . "/../assets/js/keranjang.js") ?>" defer></script>
</head>
<body class="page-keranjang">
<?php
$mhsActive = "keranjang";
require __DIR__ . "/../includes/navbar-mahasiswa.php";
?>

<main class="cart" data-cart data-ongkir="<?= (int)$ongkir ?>">
    <div class="cart__inner">

        <!-- Jejak halaman -->
        <nav class="cart-crumb" aria-label="Jejak halaman">
            <svg class="cart-crumb__home" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.8 1.8 11.9h3V21h5.4v-5.6h3.6V21h5.4v-9.1h3L12 2.8z"/></svg>
            <a href="/mahasiswa/dashboard.php">Dashboard</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">Keranjang</span>
        </nav>

        <!-- Judul halaman -->
        <header class="cart-head">
            <span class="cart-head__icon" aria-hidden="true">
                <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3.5 6.5h6.2l5.2 24h24.6l4.6-17.2H12.2"/>
                    <circle cx="18.6" cy="39" r="2.6"/>
                    <circle cx="36" cy="39" r="2.6"/>
                </svg>
            </span>
            <div>
                <h1 class="cart-head__title">Keranjang Belanja</h1>
                <p class="cart-head__sub">Pastikan kembali produk yang dipilih sebelum melanjutkan ke pembayaran</p>
            </div>
        </header>

        <?php if ($flash): ?>
            <p class="cart-flash cart-flash--<?= $flash["type"] === "sukses" ? "ok" : "err" ?>" role="status"><?= e($flash["msg"]) ?></p>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="cart-flash cart-flash--err" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if (!$items): ?>

            <section class="cart-panel cart-empty" aria-label="Keranjang kosong">
                <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3.5 6.5h6.2l5.2 24h24.6l4.6-17.2H12.2"/>
                    <circle cx="18.6" cy="39" r="2.6"/>
                    <circle cx="36" cy="39" r="2.6"/>
                </svg>
                <h2>Keranjang masih kosong</h2>
                <p>Pilih merchandise favoritmu di katalog, lalu tambahkan ke keranjang.</p>
                <a class="cart-cta cart-cta--inline" href="/mahasiswa/katalog.php"><span>Lihat Katalog</span><span class="cart-cta__arrow" aria-hidden="true">→</span></a>
            </section>

        <?php else: ?>

            <div class="cart-layout">

                <!-- ===== Kiri: daftar produk ===== -->
                <section class="cart-panel cart-list" aria-label="Daftar produk di keranjang">

                    <div class="cart-bar">
                        <label class="cart-selectall">
                            <input class="cart-check" type="checkbox" data-select-all>
                            <span>Pilih Semua (<span data-count><?= $jumlahProduk ?></span>)</span>
                        </label>

                        <!-- Checkbox tiap item memakai atribut form="form-hapus-terpilih" -->
                        <button class="cart-bulkdel" type="submit" form="form-hapus-terpilih" data-bulk-delete disabled>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                            <span>Hapus Yang Dipilih</span>
                        </button>
                    </div>

                    <ul class="cart-items">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $foto = foto_url($item["foto"]);
                            $pesanStok = $item["stok"] < 1
                                ? "Stok habis — hapus item ini dari keranjang."
                                : "Stok tidak cukup (tersedia " . $item["stok"] . ")";
                            ?>
                            <li class="cart-item<?= $item["stok_kurang"] ? " is-short" : "" ?>"
                                data-item
                                data-id="<?= $item["id"] ?>"
                                data-harga="<?= $item["harga"] ?>"
                                data-stok="<?= $item["stok"] ?>">

                                <input class="cart-check cart-item__check" type="checkbox"
                                       name="ids[]" value="<?= $item["id"] ?>" form="form-hapus-terpilih"
                                       aria-label="Pilih <?= e($item["nama"]) ?>">

                                <div class="cart-item__photo">
                                    <?php if ($foto !== ""): ?>
                                        <img src="<?= e($foto) ?>" alt="<?= e($item["nama"]) ?>" loading="lazy">
                                    <?php else: ?>
                                        <span>(tanpa foto)</span>
                                    <?php endif; ?>
                                </div>

                                <div class="cart-item__info">
                                    <h2 class="cart-item__name"><?= e($item["nama"]) ?></h2>
                                    <p class="cart-item__cat"><?= e($item["kategori"]) ?></p>
                                    <?php if ($item["ukuran"] !== ""): ?>
                                        <p class="cart-item__size">Ukuran: <?= e($item["ukuran"]) ?></p>
                                    <?php endif; ?>
                                    <p class="cart-item__unit"><?= rupiah($item["harga"]) ?></p>
                                    <p class="cart-item__warn" data-warn<?= $item["stok_kurang"] ? "" : " hidden" ?>><?= e($pesanStok) ?></p>
                                </div>

                                <!-- Ubah jumlah: tanpa JavaScript tetap bisa lewat tombol "Perbarui" -->
                                <form class="cart-item__qty" method="POST" action="/mahasiswa/keranjang.php" data-qty-form>
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id_merchandise" value="<?= $item["id"] ?>">
                                    <div class="cart-stepper">
                                        <button class="cart-stepper__btn" type="button" data-step="-1"
                                                aria-label="Kurangi jumlah <?= e($item["nama"]) ?>"<?= $item["stok"] < 1 ? " disabled" : "" ?>>−</button>
                                        <input class="cart-stepper__input" type="number" name="jumlah"
                                               value="<?= $item["jumlah"] ?>" min="1" max="<?= $item["stok"] ?>"
                                               inputmode="numeric" autocomplete="off" required
                                               aria-label="Jumlah <?= e($item["nama"]) ?>"<?= $item["stok"] < 1 ? " disabled" : "" ?>>
                                        <button class="cart-stepper__btn" type="button" data-step="1"
                                                aria-label="Tambah jumlah <?= e($item["nama"]) ?>"<?= $item["stok"] < 1 ? " disabled" : "" ?>>+</button>
                                    </div>
                                    <noscript><button class="cart-update" type="submit">Perbarui</button></noscript>
                                </form>

                                <p class="cart-item__total" data-subtotal><?= rupiah($item["subtotal"]) ?></p>

                                <form class="cart-item__del" method="POST" action="/mahasiswa/keranjang.php"
                                      data-confirm="Hapus <?= e($item["nama"]) ?> dari keranjang?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="hapus">
                                    <input type="hidden" name="id_merchandise" value="<?= $item["id"] ?>">
                                    <button type="submit" aria-label="Hapus <?= e($item["nama"]) ?> dari keranjang" title="Hapus">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="cart-foot">
                        <a class="cart-back" href="/mahasiswa/katalog.php"><span aria-hidden="true">←</span> Lanjut Belanja</a>
                    </div>
                </section>

                <!-- ===== Kanan: ringkasan belanja ===== -->
                <aside class="cart-panel cart-summary" aria-labelledby="judul-ringkasan">
                    <h2 class="cart-summary__title" id="judul-ringkasan">Ringkasan Belanja</h2>

                    <dl class="cart-sum">
                        <div class="cart-sum__row cart-sum__row--sub">
                            <dt>Subtotal (<span data-sum-count><?= $jumlahProduk ?></span> Produk)</dt>
                            <dd data-sum-subtotal><?= rupiah($subtotal) ?></dd>
                        </div>
                        <?php if ($ongkir > 0): ?>
                            <div class="cart-sum__row">
                                <dt>Estimasi Ongkos Kirim</dt>
                                <dd data-sum-ongkir><?= rupiah($ongkir) ?></dd>
                            </div>
                        <?php endif; ?>
                        <div class="cart-sum__row cart-sum__row--total">
                            <dt>Total Pembayaran</dt>
                            <dd data-sum-total><?= rupiah($totalBayar) ?></dd>
                        </div>
                    </dl>

                    <?php if ($ongkir > 0): ?>
                        <p class="cart-sum__note">Ongkos kirim hanya estimasi dan dapat berubah saat checkout.</p>
                    <?php endif; ?>

                    <!-- Menuju checkout.php yang memproses seluruh isi keranjang -->
                    <a class="cart-cta<?= $adaStokKurang ? " is-disabled" : "" ?>" href="/mahasiswa/checkout.php" data-cta<?= $adaStokKurang ? ' aria-disabled="true" tabindex="-1"' : "" ?>>
                        <img src="/assets/img/icon-credit-card.png" alt="" width="48" height="48">
                        <span>Lanjut Ke Pembayaran</span>
                        <span class="cart-cta__arrow" aria-hidden="true">→</span>
                    </a>

                    <p class="cart-sum__warn" data-cta-warn<?= $adaStokKurang ? "" : " hidden" ?>>Perbarui jumlah item bertanda merah sebelum melanjutkan ke checkout.</p>
                </aside>

            </div>

            <!-- Form "Hapus Yang Dipilih": checkbox item & tombolnya terhubung lewat atribut form="..." -->
            <form id="form-hapus-terpilih" method="POST" action="/mahasiswa/keranjang.php" hidden>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="hapus_terpilih">
            </form>

        <?php endif; ?>

    </div>

    <!-- Pesan singkat dari aksi AJAX (jumlah disesuaikan, gagal simpan, dll.) -->
    <div class="cart-toast" data-toast role="status" aria-live="polite"></div>
</main>

</body>
</html>
