<?php
// Menu navbar: ubah teks / URL di sini (ganti '#' jika halamannya sudah ada).
$navLinks = [
    'BERANDA'      => 'index.php',
    'PRODUK'       => '#',
    'TENTANG KAMI' => '#',
];
?>
<header class="navbar">
    <a class="navbar__brand" href="index.php">
        <img src="assets/img/logo-navbar.png" alt="JTI Merch - Technology for a Brighter Future">
    </a>
    <nav class="navbar__menu" aria-label="Menu utama">
        <?php foreach ($navLinks as $text => $url): ?>
            <a class="navbar__link" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($text) ?></a>
        <?php endforeach; ?>
        <a class="navbar__icon" href="#" aria-label="Cari"><img src="assets/img/icon-search.png" alt=""></a>
        <a class="navbar__icon" href="#" aria-label="Keranjang"><img src="assets/img/icon-cart.png" alt=""></a>
    </nav>
</header>
