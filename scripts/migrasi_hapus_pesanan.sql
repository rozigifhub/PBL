-- Migrasi: fitur "Hapus Yang Dipilih" di daftar pesanan (admin & mahasiswa).
-- Jalankan SEKALI di database aplikasi, mis.:
--   psql -U postgres -d merchandise -f scripts/migrasi_hapus_pesanan.sql
--
-- Pesanan TIDAK dihapus dari tabel `pesanan`. Pesanan hanya disembunyikan dari daftar
-- milik pihak yang menghapus (sisi 'admin' atau 'mahasiswa'), sehingga data pembayaran,
-- stok, dompet, dan laporan penjualan tetap utuh dan konsisten.

CREATE TABLE IF NOT EXISTS pesanan_disembunyikan (
    id_pesanan  INTEGER      NOT NULL REFERENCES pesanan (id_pesanan) ON DELETE CASCADE,
    sisi        VARCHAR(10)  NOT NULL CHECK (sisi IN ('admin', 'mahasiswa')),
    id_akun     INTEGER,                              -- siapa yang menghapus (tanpa FK agar tidak menghalangi hapus akun)
    dihapus_pada TIMESTAMP   NOT NULL DEFAULT NOW(),
    PRIMARY KEY (id_pesanan, sisi)
);
