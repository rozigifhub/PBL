<?php
// Navbar halaman admin (urutan menu mengikuti desain UI/UX).
//
// Pemakaian di halaman admin:
//   $adminActive = "dashboard";   // dashboard | pesanan | kategori | produk
//   require __DIR__ . "/../includes/navbar-admin.php";
//
// Ubah teks / URL menu di array $adminLinks di bawah.
// Path memakai awalan "/" (absolut dari root web), sama seperti includes/navbar.php.
$adminActive = $adminActive ?? "";

$adminLinks = [
    "dashboard" => ["DASHBOARD",       "/admin/dashboard.php"],
    "pesanan"   => ["KELOLA PESANAN",  "/admin/pesanan.php"],
    "kategori"  => ["KELOLA KATEGORI", "/admin/kategori.php"],
    "produk"    => ["KELOLA PRODUK",   "/admin/merchandise.php"],
];
?>
<header class="navbar navbar--admin">
    <a class="navbar__brand" href="/admin/dashboard.php">
        <img src="/assets/img/logo-navbar.webp" alt="JTI Merch - Technology for a Brighter Future">
    </a>

    <nav class="navbar__menu" aria-label="Menu admin">
        <?php foreach ($adminLinks as $kunci => [$teks, $url]): ?>
            <a class="navbar__link" href="<?= htmlspecialchars($url) ?>"<?= $kunci === $adminActive ? ' aria-current="page"' : "" ?>><?= htmlspecialchars($teks) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="navbar__tools">
        <!-- Cari: sama seperti navbar publik, masih placeholder (belum ada halaman pencarian). -->
        <a class="navbar__icon" href="#" aria-label="Cari"><img src="/assets/img/icon-search.png" alt=""></a>

        <!-- Keranjang pada desain diarahkan ke Kelola Pesanan: keranjang.php khusus mahasiswa
             (admin yang membukanya akan dilempar ke halaman login). -->
        <a class="navbar__icon" href="/admin/pesanan.php" aria-label="Pesanan"><img src="/assets/img/icon-cart.png" alt=""></a>

        <!-- Akun: menu kecil berisi nama admin + Logout (tanpa JS pun tetap bisa dibuka/ditutup). -->
        <details class="navbar__account">
            <summary class="navbar__icon" aria-label="Akun admin">
                <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="24" cy="24" r="21"/>
                    <circle cx="24" cy="19" r="6.5"/>
                    <path d="M11.5 38c1.8-6.2 6.7-9.5 12.5-9.5S34.7 31.8 36.5 38"/>
                </svg>
            </summary>
            <div class="navbar__pop">
                <p class="navbar__who">Masuk sebagai <b><?= htmlspecialchars((string)($_SESSION["username"] ?? "")) ?></b></p>
                <a class="navbar__logout" href="/logout.php">Logout</a>
            </div>
        </details>
    </div>
</header>
