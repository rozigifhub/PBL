<?php

/**
 * Helper gambar: perkecil + encode ulang ke WebP dengan GD.
 * Dipakai upload foto produk (admin/merchandise.php), halaman katalog
 * (thumbnail), dan scripts/buat_thumbnail.php.
 *
 * Alasan: foto asli dari kamera/HP bisa 1-2 MB; katalog memuat satu foto per
 * produk, jadi ukuran halaman dan waktu muat membengkak. Foto detail dibatasi
 * 1000 px dan thumbnail kartu 400 px, keduanya WebP (~30-60 KB).
 */

const GAMBAR_SISI_UTAMA  = 1000;      // px, sisi terpanjang foto detail
const GAMBAR_SISI_THUMB  = 400;       // px, sisi terpanjang kartu katalog
const GAMBAR_KUALITAS    = 80;        // kualitas WebP (0-100)
const GAMBAR_MAKS_PIKSEL = 25000000;  // tolak foto raksasa (GD memakai ~4 byte/piksel)

function gambar_tersedia(): bool
{
    return extension_loaded("gd") && function_exists("imagewebp");
}

/** Nama thumbnail dari nama file foto: "abc.webp" / "abc.jpg" → "abc_t.webp". */
function thumb_nama(string $foto): string
{
    return pathinfo(basename($foto), PATHINFO_FILENAME) . "_t.webp";
}

/** Muat gambar ke GD (orientasi EXIF JPEG dibetulkan). null bila gagal/terlalu besar. */
function gambar_muat(string $path, string $mime): ?GdImage
{
    $info = @getimagesize($path);
    if ($info === false || $info[0] * $info[1] > GAMBAR_MAKS_PIKSEL) {
        return null;
    }

    $img = match ($mime) {
        "image/jpeg" => @imagecreatefromjpeg($path),
        "image/png"  => @imagecreatefrompng($path),
        "image/webp" => @imagecreatefromwebp($path),
        default      => false,
    };
    if ($img === false) {
        return null;
    }

    // Foto dari HP sering tersimpan "miring" + tag orientasi EXIF
    if ($mime === "image/jpeg" && function_exists("exif_read_data")) {
        $exif  = @exif_read_data($path);
        $putar = match ((int)($exif["Orientation"] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($putar !== 0) {
            $diputar = imagerotate($img, $putar, 0);
            if ($diputar !== false) {
                $img = $diputar;
            }
        }
    }

    return $img;
}

/** Perkecil agar sisi terpanjang <= $sisiMaks (tidak pernah memperbesar); transparansi dijaga. */
function gambar_perkecil(GdImage $src, int $sisiMaks): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $skala = min(1, $sisiMaks / max($w, $h));
    $nw = max(1, (int)round($w * $skala));
    $nh = max(1, (int)round($h * $skala));

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    return $dst;
}

/**
 * Simpan satu gambar sumber sebagai beberapa file WebP.
 * $target: [path_tujuan => sisi_maks_px, ...]
 * Return false (dan menghapus file yang sudah tertulis) bila ada yang gagal.
 */
function gambar_simpan_webp(string $sumber, string $mime, array $target): bool
{
    if (!gambar_tersedia()) {
        return false;
    }

    // Hanya dipakai admin/CLI sesekali: izinkan memori lebih untuk dekode foto besar
    @ini_set("memory_limit", "256M");

    $img = gambar_muat($sumber, $mime);
    if ($img === null) {
        return false;
    }

    $ditulis = [];
    foreach ($target as $tujuan => $sisi) {
        $kecil = gambar_perkecil($img, (int)$sisi);
        if (!imagewebp($kecil, $tujuan, GAMBAR_KUALITAS)) {
            foreach (array_merge($ditulis, [$tujuan]) as $f) {
                @unlink($f);
            }
            return false;
        }
        $ditulis[] = $tujuan;
    }

    return true;
}
