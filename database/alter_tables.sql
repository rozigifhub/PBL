-- =====================================================================
-- alter_tables.sql
-- Perbaikan skema untuk database "merchandise" (PostgreSQL)
--
-- Cara pakai (psql):
--   \connect merchandise
--   \i alter_tables.sql
-- =====================================================================


-- =====================================================================
-- 1. detail_pesanan: snapshot harga satuan
--    Tanpa kolom ini, jika harga merchandise berubah, histori
--    transaksi lama tidak bisa dihitung ulang dengan benar.
--    PENTING: saat membuat kode checkout nanti, isi kolom ini dengan
--    nilai merchandise.harga PADA SAAT ORDER dibuat (bukan setelahnya).
-- =====================================================================

ALTER TABLE detail_pesanan
    ADD COLUMN harga_satuan NUMERIC(12,2);

-- Backfill data lama dengan harga saat ini (perkiraan terbaik yang tersedia)
UPDATE detail_pesanan d
SET harga_satuan = m.harga
FROM merchandise m
WHERE m.id_merchandise = d.id_merchandise
  AND d.harga_satuan IS NULL;

ALTER TABLE detail_pesanan
    ALTER COLUMN harga_satuan SET NOT NULL;

ALTER TABLE detail_pesanan
    ADD CONSTRAINT chk_detail_pesanan_harga_satuan
        CHECK (harga_satuan > 0);


-- =====================================================================
-- 2. pengiriman
--    a) id_admin dibolehkan NULL: pesanan berstatus 'Belum Dikirim'
--       belum tentu punya admin yang ditugaskan.
--    b) alamat diubah ke TEXT agar tidak terpotong di 255 karakter
--       (di PostgreSQL, TEXT dan VARCHAR setara dari sisi performa).
-- =====================================================================

ALTER TABLE pengiriman
    ALTER COLUMN id_admin DROP NOT NULL;

ALTER TABLE pengiriman
    ALTER COLUMN alamat TYPE TEXT;


-- =====================================================================
-- 3. Index untuk kolom foreign key
--    PostgreSQL TIDAK membuat index otomatis di kolom FK.
--    Index ini mempercepat join dan query per-user/per-produk.
-- =====================================================================

CREATE INDEX idx_detail_pesanan_merchandise
    ON detail_pesanan (id_merchandise);

CREATE INDEX idx_pembayaran_pesanan
    ON pembayaran (id_pesanan);

CREATE INDEX idx_pesanan_akun
    ON pesanan (id_akun);

CREATE INDEX idx_merchandise_kategori
    ON merchandise (id_kategori);


-- =====================================================================
-- 4. Perbaikan ejaan status pembayaran
--    'Kadaluarsa' -> 'Kedaluwarsa' (sesuai KBBI).
--    Catatan: kode PHP baru harus memakai 'Kedaluwarsa'.
-- =====================================================================

UPDATE pembayaran
SET status_bayar = 'Kedaluwarsa'
WHERE status_bayar = 'Kadaluwarsa';

ALTER TABLE pembayaran
    DROP CONSTRAINT chk_pembayaran_status;

ALTER TABLE pembayaran
    ADD CONSTRAINT chk_pembayaran_status
        CHECK (
            status_bayar IN (
                'Menunggu',
                'Berhasil',
                'Gagal',
                'Kedaluwarsa'
            )
        );


-- =====================================================================
-- 5. Kolom audit created_at
--    (pesanan/pembayaran/pengiriman sudah punya kolom tanggal masing2)
-- =====================================================================

ALTER TABLE akun_login
    ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT now();

ALTER TABLE mahasiswa
    ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT now();

ALTER TABLE kategori_merchandise
    ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT now();

ALTER TABLE merchandise
    ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT now();


-- =====================================================================
-- OPSIONAL — jalankan hanya jika memang dibutuhkan
-- =====================================================================

-- Soft-delete akun: akun yang pernah order tidak bisa dihapus
-- (ON DELETE RESTRICT), jadi lebih baik dinonaktifkan daripada dihapus:
-- ALTER TABLE akun_login ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE;

-- Index bantu pencarian katalog (nama tidak case-sensitive):
-- CREATE INDEX idx_merchandise_nama ON merchandise (LOWER(nama_merchandise));
