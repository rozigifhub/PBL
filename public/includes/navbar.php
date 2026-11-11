<?php
// NAVBAR GLOBAL: satu-satunya navbar untuk halaman publik & mahasiswa
// (beranda, login, register, katalog, produk, keranjang, dst.). Navbar admin tetap di navbar-admin.php.
//
// Pemakaian di halaman:
//   $mhsActive = "produk";   // (opsional) beranda | dashboard | produk | pesanan | tentang | keranjang
//   require __DIR__ . "/includes/navbar.php";        // dari subfolder: __DIR__ . "/../includes/navbar.php"
//
// Butuh CSS di <head> halaman pemanggil: assets/css/style.css, LALU assets/css/navbar-mahasiswa.css.
//
// Menu & ikon SELALU tampil lengkap, baik sudah login maupun belum (tamu). Yang berbeda hanya
// pojok kanan atas: tamu melihat tautan LOGIN, yang sudah login melihat menu akun (+ saldo untuk mahasiswa).
// Tamu yang membuka DASHBOARD / PESANAN / keranjang / checkout ditolak oleh halaman tujuannya
// (require_mahasiswa() di config/functions.php), lalu dilempar ke login.php?error=menu|cart|buy.
//
// Path memakai awalan "/" (absolut dari root web) agar benar saat di-include dari subfolder mana pun.
// Semua variabel di file ini berawalan "nav" supaya tidak bertabrakan dengan variabel halaman.
$mhsActive = $mhsActive ?? "";

$navLogin = !empty($_SESSION["id_akun"]);
$navRole  = (string)($_SESSION["role"] ?? "");
$navAdmin = $navLogin && $navRole === "Admin";
$navMhs   = $navLogin && $navRole === "Mahasiswa";

// Urutan menu mengikuti desain UI/UX. Ubah teks / URL di sini.
// Admin yang membuka halaman publik diarahkan ke halaman admin yang setara.
$navLinks = [
    "beranda"   => ["BERANDA",      "/index.php"],
    "dashboard" => ["DASHBOARD",    $navAdmin ? "/admin/dashboard.php" : "/mahasiswa/dashboard.php"],
    "produk"    => ["PRODUK",       "/mahasiswa/katalog.php"],
    "pesanan"   => ["PESANAN",      $navAdmin ? "/admin/pesanan.php" : "/mahasiswa/riwayat.php"],
    "tentang"   => ["TENTANG KAMI", "#"],   // belum ada halamannya; ganti "#" dengan URL halaman Tentang Kami nanti
];

// Tautan LOGIN. Parameter next (kembali ke halaman asal setelah login) hanya dipakai untuk
// halaman /mahasiswa/..., karena login.php memang hanya menerima path itu.
$navUri      = (string)($_SERVER["REQUEST_URI"] ?? "");
$navScript   = basename((string)($_SERVER["SCRIPT_NAME"] ?? ""));
$navUrlLogin = "/login.php" . (strpos($navUri, "/mahasiswa/") === 0 ? "?next=" . rawurlencode($navUri) : "");

// Saldo dompet untuk pill di navbar (hanya mahasiswa; dilewati bila $pdo belum ada / tabel belum ada)
$navSaldo = null;
if ($navMhs && isset($pdo)) {
    try {
        $navStmt = $pdo->prepare("SELECT COALESCE((SELECT saldo FROM dompet WHERE id_akun = :id), 0)");
        $navStmt->execute([":id" => (int)$_SESSION["id_akun"]]);
        $navSaldo = (float)$navStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Navbar: gagal membaca saldo dompet: " . $e->getMessage());
    }
}
?>
<header class="navbar navbar--mhs">
    <a class="navbar__brand" href="/index.php">
        <img src="/assets/img/logo-navbar.webp" alt="JTI Merch - Technology for a Brighter Future">
    </a>

    <nav class="navbar__menu" aria-label="Menu utama">
        <?php foreach ($navLinks as $navKunci => [$navTeks, $navUrl]): ?>
            <a class="navbar__link" href="<?= htmlspecialchars($navUrl) ?>"<?= $navKunci === $mhsActive ? ' aria-current="page"' : "" ?>><?= htmlspecialchars($navTeks) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="navbar__tools">
        <!-- Cari: kolom pencarian produk ada di halaman katalog (id="cari-produk"); katalog boleh dibuka tamu. -->
        <a class="navbar__icon" href="/mahasiswa/katalog.php#cari-produk" aria-label="Cari produk"><img src="/assets/img/icon-search.png" alt=""></a>

        <?php if ($navSaldo !== null): ?>
            <a class="navbar__saldo" href="/mahasiswa/dompet.php" aria-label="Dompet saya">Rp <?= number_format($navSaldo, 0, ",", ".") ?></a>
        <?php endif; ?>

        <!-- Keranjang: tamu yang membukanya dilempar ke login.php?error=cart oleh keranjang.php.
             Admin diarahkan ke Kelola Pesanan (sama seperti navbar-admin.php). -->
        <a class="navbar__icon" href="<?= $navAdmin ? "/admin/pesanan.php" : "/mahasiswa/keranjang.php" ?>" aria-label="Keranjang"<?= $mhsActive === "keranjang" ? ' aria-current="page"' : "" ?>><img src="/assets/img/icon-cart.png" alt=""></a>

        <?php if (!$navLogin): ?>
            <!-- Pojok kanan atas, tamu: LOGIN -->
            <a class="navbar__link" href="<?= htmlspecialchars($navUrlLogin) ?>"<?= $navScript === "login.php" ? ' aria-current="page"' : "" ?>>LOGIN</a>
        <?php else: ?>
            <!-- Pojok kanan atas, sudah login: menu kecil berisi nama + (mahasiswa: Dompet, Kelola Profil) + Logout.
                 Tanpa JS pun tetap bisa dibuka/ditutup. -->
            <details class="navbar__account">
                <summary class="navbar__icon" aria-label="Akun saya">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="24" cy="24" r="21"/>
                        <circle cx="24" cy="19" r="6.5"/>
                        <path d="M11.5 38c1.8-6.2 6.7-9.5 12.5-9.5S34.7 31.8 36.5 38"/>
                    </svg>
                </summary>
                <div class="navbar__pop">
                    <p class="navbar__who">Masuk sebagai <b><?= htmlspecialchars((string)($_SESSION["username"] ?? "")) ?></b></p>
                    <?php if ($navMhs): ?>
                        <a class="navbar__sub" href="/mahasiswa/dompet.php">Dompet</a>
                        <a class="navbar__sub" href="/mahasiswa/profil.php">Kelola Profil</a>
                    <?php endif; ?>
                    <a class="navbar__logout" href="/logout.php">Logout</a>
                </div>
            </details>
        <?php endif; ?>
    </div>
</header>
