<?php

/**
 * Buat thumbnail (400 px, WebP) untuk foto produk yang sudah ada.
 * File asli TIDAK diubah dan database TIDAK disentuh; katalog otomatis memakai
 * thumbnail bila ada (lihat foto_thumb_url() di mahasiswa/katalog.php).
 *
 * Jalankan di server (hanya lewat CLI):
 *   php scripts/buat_thumbnail.php
 * Aman diulang: thumbnail yang sudah ada dilewati.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Hanya dapat dijalankan lewat CLI.\n");
}

require_once __DIR__ . "/../config/gambar.php";

if (!gambar_tersedia()) {
    exit("Ekstensi GD dengan dukungan WebP tidak tersedia (apt install php8.2-gd).\n");
}

$dir = __DIR__ . "/../public/uploads/merchandise";
if (!is_dir($dir)) {
    exit("Folder tidak ditemukan: {$dir}\n");
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$dibuat = $dilewati = $gagal = 0;

foreach (glob($dir . "/*.{jpg,jpeg,png,webp}", GLOB_BRACE) as $path) {
    $nama = basename($path);

    // Lewati thumbnail itu sendiri
    if (str_ends_with($nama, "_t.webp")) {
        continue;
    }

    $thumb = $dir . "/" . thumb_nama($nama);
    if (is_file($thumb)) {
        $dilewati++;
        continue;
    }

    $mime = $finfo->file($path);
    if (gambar_simpan_webp($path, $mime, [$thumb => GAMBAR_SISI_THUMB])) {
        printf("OK    %s  (%d KB -> %d KB)\n", $nama, filesize($path) / 1024, filesize($thumb) / 1024);
        $dibuat++;
    } else {
        echo "GAGAL {$nama}\n";
        $gagal++;
    }
}

echo "\nSelesai: {$dibuat} dibuat, {$dilewati} dilewati, {$gagal} gagal.\n";
