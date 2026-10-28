<?php
// Navbar halaman mahasiswa (urutan menu mengikuti desain UI/UX).
//
// Pemakaian di halaman mahasiswa:
//   $mhsActive = "keranjang";   // beranda | dashboard | pesanan | keranjang
//   require __DIR__ . "/../includes/navbar-mahasiswa.php";
//
// Butuh CSS: assets/css/navbar-mahasiswa.css (dimuat setelah style.css).
// Ubah teks / URL menu di array $mhsLinks di bawah (beranda | dashboard | produk | pesanan | tentang; keranjang hanya untuk ikon).
// Path memakai awalan "/" (absolut dari root web), sama seperti includes/navbar.php.
$mhsActive = $mhsActive ?? "";

$mhsLinks = [
    "beranda"   => ["BERANDA",   "/index.php"],
    "dashboard" => ["DASHBOARD", "/mahasiswa/dashboard.php"],
    "produk"    => ["PRODUK",    "/mahasiswa/katalog.php"],
    "pesanan"   => ["PESANAN",   "/mahasiswa/riwayat.php"],
    "tentang"   => ["TENTANG KAMI", "#"],   // belum ada halamannya; ganti "#" dengan URL halaman Tentang Kami nanti
];

// Saldo dompet untuk pill di navbar (dilewati bila belum login / tabel belum ada)
$saldoNavbar = null;
if (!empty($_SESSION["id_akun"]) && isset($pdo)) {
    try {
        $stmtSaldo = $pdo->prepare("SELECT COALESCE((SELECT saldo FROM dompet WHERE id_akun = :id), 0)");
        $stmtSaldo->execute([":id" => (int)$_SESSION["id_akun"]]);
        $saldoNavbar = (float)$stmtSaldo->fetchColumn();
    } catch (PDOException $e) {
        error_log("Navbar: gagal membaca saldo dompet: " . $e->getMessage());
    }
}
?>
<header class="navbar navbar--mhs">
    <a class="navbar__brand" href="/index.php">
        <img src="/assets/img/logo-navbar.png" alt="JTI Merch - Technology for a Brighter Future">
    </a>

    <nav class="navbar__menu" aria-label="Menu mahasiswa">
        <?php foreach ($mhsLinks as $kunci => [$teks, $url]): ?>
            <a class="navbar__link" href="<?= htmlspecialchars($url) ?>"<?= $kunci === $mhsActive ? ' aria-current="page"' : "" ?>><?= htmlspecialchars($teks) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="navbar__tools">
        <!-- Cari: kolom pencarian produk ada di halaman katalog (id="cari-produk"). -->
        <a class="navbar__icon" href="/mahasiswa/katalog.php#cari-produk" aria-label="Cari produk"><img src="/assets/img/icon-search.png" alt=""></a>

        <?php if ($saldoNavbar !== null): ?>
            <a class="navbar__saldo" href="/mahasiswa/dompet.php" aria-label="Dompet saya">Rp <?= number_format($saldoNavbar, 0, ",", ".") ?></a>
        <?php endif; ?>

        <a class="navbar__icon" href="/mahasiswa/keranjang.php" aria-label="Keranjang"<?= $mhsActive === "keranjang" ? ' aria-current="page"' : "" ?>><img src="/assets/img/icon-cart.png" alt=""></a>

        <!-- Akun: menu kecil berisi nama + Kelola Profil + Logout (tanpa JS pun tetap bisa dibuka/ditutup). -->
        <?php if (empty($_SESSION["id_akun"])): ?>
            <a class="navbar__link" href="/login.php?next=<?= rawurlencode($_SERVER["REQUEST_URI"]) ?>">LOGIN</a>
        <?php else: ?>
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
                <a class="navbar__sub" href="/mahasiswa/dompet.php">Dompet</a>
                <a class="navbar__sub" href="/mahasiswa/profil.php">Kelola Profil</a>
                <a class="navbar__logout" href="/logout.php">Logout</a>
            </div>
        </details>
        <?php endif; ?>
    </div>
</header>
