<?php

/**
 * Buat versi WebP aset statis beranda/navbar dari PNG/JPG aslinya (sekali jalan, CLI).
 * File asli tidak diubah. Hasilnya sudah di-commit; jalankan ulang hanya bila gambar sumber berubah:
 *   php scripts/buat_webp_aset.php
 *
 * Banner dibuat dalam tiga lebar (800 / 1200 / 1672 px) untuk <picture srcset> di index.php.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Hanya dapat dijalankan lewat CLI.\n");
}

require_once __DIR__ . "/../config/gambar.php";

if (!gambar_tersedia()) {
    exit("Ekstensi GD dengan dukungan WebP tidak tersedia.\n");
}

$img = __DIR__ . "/../public/assets/img";

// [sumber, tujuan, sisi terpanjang maks (null = ukuran asli), kualitas]
$tugas = [
    ["logo-navbar.png",              "logo-navbar.webp",                 null, 90],
    ["card-frame.png",               "card-frame.webp",                  null, 90],
    ["beranda/bg-beranda-atas.jpg",  "beranda/bg-beranda-atas.webp",     null, 80],
    ["beranda/bg-beranda-bawah.jpg", "beranda/bg-beranda-bawah.webp",    null, 80],
    ["beranda/banner-diskon.png",    "beranda/banner-diskon-800.webp",   800,  82],
    ["beranda/banner-diskon.png",    "beranda/banner-diskon-1200.webp",  1200, 82],
];

$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($tugas as [$sumber, $tujuan, $sisi, $kualitas]) {
    $src  = "{$img}/{$sumber}";
    $dst  = "{$img}/{$tujuan}";
    $mime = $finfo->file($src);
    $gd   = gambar_muat($src, $mime);

    if ($gd === null) {
        echo "GAGAL {$sumber}\n";
        continue;
    }

    $hasil = $sisi === null ? $gd : gambar_perkecil($gd, $sisi);

    // gambar_muat() untuk PNG/JPG bukan alpha-aware pada salinan tanpa resize: pastikan alpha dijaga
    if ($sisi === null) {
        imagepalettetotruecolor($hasil);
        imagealphablending($hasil, false);
        imagesavealpha($hasil, true);
    }

    imagewebp($hasil, $dst, $kualitas);
    printf("OK  %-34s %7.1f KB -> %-36s %7.1f KB (%dx%d)\n",
        $sumber, filesize($src) / 1024, $tujuan, filesize($dst) / 1024, imagesx($hasil), imagesy($hasil));
}
