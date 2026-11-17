<?php

/**
 * Hapus pesanan dari DAFTAR (dipakai admin/pesanan.php dan mahasiswa/riwayat.php).
 *
 * Ini "hapus lunak" per sisi: baris pesanan tidak dihapus dari tabel `pesanan`, hanya dicatat di
 * `pesanan_disembunyikan` (lihat scripts/migrasi_hapus_pesanan.sql). Dengan begitu pembayaran,
 * stok, dompet, dan laporan penjualan tidak rusak, dan admin yang menghapus tidak membuat
 * riwayat mahasiswa hilang (dan sebaliknya).
 *
 * Hanya pesanan yang sudah berakhir yang boleh dihapus. Pesanan yang masih berjalan
 * (menunggu bayar, diproses, siap diambil) harus tetap terlihat supaya tidak terlewat.
 */

const PESANAN_STATUS_BISA_DIHAPUS = ["Selesai", "Dibatalkan"];
const PESANAN_SISI_ADMIN          = "admin";
const PESANAN_SISI_MAHASISWA      = "mahasiswa";
const PESANAN_HAPUS_MAKS          = 500;   // batas jumlah id per permintaan

function pesanan_bisa_dihapus(?string $status): bool
{
    return in_array((string)$status, PESANAN_STATUS_BISA_DIHAPUS, true);
}

function pesanan_validasi_sisi(string $sisi): string
{
    if (!in_array($sisi, [PESANAN_SISI_ADMIN, PESANAN_SISI_MAHASISWA], true)) {
        throw new InvalidArgumentException("Sisi daftar pesanan tidak dikenal.");
    }
    return $sisi;
}

/**
 * Potongan WHERE untuk menyaring pesanan yang sudah dihapus dari daftar sisi tertentu.
 * Contoh: "... WHERE p.id_akun = :id AND " . sql_pesanan_tampil(PESANAN_SISI_MAHASISWA)
 */
function sql_pesanan_tampil(string $sisi, string $alias = "p"): string
{
    $sisi = pesanan_validasi_sisi($sisi);
    return "NOT EXISTS (SELECT 1 FROM pesanan_disembunyikan h WHERE h.id_pesanan = {$alias}.id_pesanan AND h.sisi = '{$sisi}')";
}

/**
 * Sembunyikan pesanan dari daftar $sisi.
 *
 * @param array    $ids        id pesanan dari permintaan (tidak dipercaya, divalidasi di sini)
 * @param int|null $idPemilik  isi dengan id_akun untuk sisi mahasiswa: hanya pesanan miliknya yang diproses
 * @return array{dihapus: array<int,string>, dilewati: int}   dihapus = [id_pesanan => status]
 */
function hapus_pesanan_dari_daftar(PDO $pdo, array $ids, string $sisi, ?int $idPemilik = null, ?int $idAktor = null): array
{
    $sisi = pesanan_validasi_sisi($sisi);

    $bersih = [];
    foreach ($ids as $nilai) {
        $n = filter_var($nilai, FILTER_VALIDATE_INT);
        if ($n !== false && $n > 0) {
            $bersih[$n] = true;
        }
    }
    $ids = array_slice(array_keys($bersih), 0, PESANAN_HAPUS_MAKS);
    if (!$ids) {
        return ["dihapus" => [], "dilewati" => 0];
    }

    $params = [];
    $tanda  = [];
    foreach ($ids as $i => $id) {
        $tanda[]           = ":id{$i}";
        $params[":id{$i}"] = $id;
    }
    $daftarId = implode(",", $tanda);

    // Ambil status terkini dari database (jangan percaya status dari browser).
    $sql = "SELECT id_pesanan, status_pesanan FROM pesanan WHERE id_pesanan IN ({$daftarId})";
    if ($idPemilik !== null) {
        $sql .= " AND id_akun = :pemilik";
        $params[":pemilik"] = $idPemilik;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ditemukan = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);   // [id_pesanan => status_pesanan]

    $layak = [];
    foreach ($ditemukan as $id => $status) {
        if (pesanan_bisa_dihapus($status)) {
            $layak[(int)$id] = (string)$status;
        }
    }

    if ($layak) {
        $params = [":sisi" => $sisi, ":aktor" => $idAktor];
        $tanda  = [];
        $i      = 0;
        foreach (array_keys($layak) as $id) {
            $tanda[]           = ":id{$i}";
            $params[":id{$i}"] = $id;
            $i++;
        }
        $stmt = $pdo->prepare("
            INSERT INTO pesanan_disembunyikan (id_pesanan, sisi, id_akun)
            SELECT id_pesanan, CAST(:sisi AS VARCHAR), CAST(:aktor AS INTEGER)
            FROM pesanan
            WHERE id_pesanan IN (" . implode(",", $tanda) . ")
            ON CONFLICT (id_pesanan, sisi) DO NOTHING
        ");
        $stmt->execute($params);
    }

    return ["dihapus" => $layak, "dilewati" => count($ids) - count($layak)];
}

/** Ubah hasil hapus_pesanan_dari_daftar() menjadi pesan flash: [tipe, teks]. */
function pesan_hapus_pesanan(array $hasil): array
{
    $n      = count($hasil["dihapus"]);
    $lewat  = (int)$hasil["dilewati"];
    $aturan = "Hanya pesanan berstatus Selesai atau Dibatalkan yang bisa dihapus.";

    if ($n === 0) {
        return ["gagal", $lewat > 0
            ? "Tidak ada pesanan yang dihapus. " . $aturan
            : "Tidak ada pesanan yang dipilih."];
    }
    if ($lewat > 0) {
        return ["sukses", "{$n} pesanan dihapus. {$lewat} pesanan dilewati karena masih berjalan. " . $aturan];
    }
    return ["sukses", "{$n} pesanan dihapus dari daftar."];
}
