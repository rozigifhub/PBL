<?php
// Menu navbar: ubah teks / URL di sini.
// Path memakai awalan "/" (absolut dari root web) agar navbar tetap benar
// saat di-include dari halaman di subfolder mana pun (mis. mahasiswa/).
$navLinks = [
    'BERANDA'      => '/index.php',
    'PRODUK'       => '/mahasiswa/katalog.php',
    'TENTANG KAMI' => '#',
];
?>
<header class="navbar">
    <a class="navbar__brand" href="/index.php">
        <img src="/assets/img/logo-navbar.png" alt="JTI Merch - Technology for a Brighter Future">
    </a>
    <nav class="navbar__menu" aria-label="Menu utama">
        <?php foreach ($navLinks as $text => $url): ?>
            <a class="navbar__link" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($text) ?></a>
        <?php endforeach; ?>
        <?php if (($_SESSION["role"] ?? "") === "Admin"): ?>
            <a class="navbar__link" href="/admin/kategori.php">KELOLA KATEGORI</a>
            <a class="navbar__link" href="/admin/merchandise.php">KELOLA PRODUK</a>
        <?php endif; ?>
        <a class="navbar__icon" href="#" aria-label="Cari"><img src="/assets/img/icon-search.png" alt=""></a>
        <a class="navbar__icon" href="/mahasiswa/keranjang.php" aria-label="Keranjang"><img src="/assets/img/icon-cart.png" alt=""></a>
    </nav>
</header>
