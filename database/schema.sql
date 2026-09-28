-- =====================================================================
-- schema.sql — Struktur database "merchandise" (PostgreSQL)
-- Versi SUDAH DIPERBAIKI: harga_satuan, id_admin nullable, alamat TEXT,
-- index FK, created_at, ejaan 'Kedaluwarsa'.
--
-- Cara pakai (psql di server):
--   CREATE DATABASE merchandise;          -- \connect TIDAK membuat database!
--   \connect merchandise
--   \i schema.sql
-- =====================================================================

CREATE TABLE akun_login (
    id_akun SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_akun_login_role
        CHECK (role IN ('Admin', 'Mahasiswa'))
);


CREATE TABLE mahasiswa (
    id_mahasiswa SERIAL PRIMARY KEY,
    id_akun INT NOT NULL UNIQUE,
    nim VARCHAR(20) NOT NULL UNIQUE,
    nama VARCHAR(100) NOT NULL,
    no_hp VARCHAR(20) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT fk_mahasiswa_akun_login
        FOREIGN KEY (id_akun)
        REFERENCES akun_login (id_akun)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


CREATE TABLE kategori_merchandise (
    id_kategori SERIAL PRIMARY KEY,
    nama_kategori VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);


CREATE TABLE merchandise (
    id_merchandise SERIAL PRIMARY KEY,
    id_kategori INT NOT NULL,
    nama_merchandise VARCHAR(100) NOT NULL,
    ukuran VARCHAR(10) NOT NULL,
    harga NUMERIC(12,2) NOT NULL,
    stok INT NOT NULL,
    foto VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_merchandise_harga
        CHECK (harga > 0),
    CONSTRAINT chk_merchandise_stok
        CHECK (stok >= 0),
    CONSTRAINT fk_merchandise_kategori
        FOREIGN KEY (id_kategori)
        REFERENCES kategori_merchandise (id_kategori)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


CREATE TABLE pesanan (
    id_pesanan SERIAL PRIMARY KEY,
    id_akun INT NOT NULL,
    tanggal_pesanan TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_harga NUMERIC(12,2) NOT NULL,
    status_pesanan VARCHAR(30) NOT NULL,
    tipe_pesanan VARCHAR(20) NOT NULL,
    persentase_dp NUMERIC(5,2) NOT NULL DEFAULT 0,
    CONSTRAINT chk_pesanan_total_harga
        CHECK (total_harga > 0),
    CONSTRAINT chk_pesanan_status
        CHECK (
            status_pesanan IN (
                'Menunggu Pembayaran',
                'Menunggu DP',
                'DP Lunas',
                'Menunggu Pelunasan',
                'Lunas',
                'Diproses',
                'Dikirim',
                'Selesai',
                'Dibatalkan'
            )
        ),
    CONSTRAINT chk_pesanan_tipe
        CHECK (
            tipe_pesanan IN (
                'Ready Stock',
                'Pre Order'
            )
        ),
    CONSTRAINT chk_pesanan_dp
        CHECK (
            persentase_dp >= 0
            AND persentase_dp <= 100
        ),
    CONSTRAINT fk_pesanan_akun
        FOREIGN KEY (id_akun)
        REFERENCES akun_login (id_akun)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


CREATE TABLE detail_pesanan (
    id_detail SERIAL PRIMARY KEY,
    id_pesanan INT NOT NULL,
    id_merchandise INT NOT NULL,
    jumlah INT NOT NULL,
    -- Snapshot harga saat order dibuat (harga bisa berubah di masa depan)
    harga_satuan NUMERIC(12,2) NOT NULL,
    subtotal NUMERIC(12,2) NOT NULL,
    CONSTRAINT uq_detail_pesanan_pesanan_merchandise
        UNIQUE (id_pesanan, id_merchandise),
    CONSTRAINT chk_detail_pesanan_jumlah
        CHECK (jumlah > 0),
    CONSTRAINT chk_detail_pesanan_harga_satuan
        CHECK (harga_satuan > 0),
    CONSTRAINT chk_detail_pesanan_subtotal
        CHECK (subtotal > 0),
    CONSTRAINT fk_detail_pesanan_pesanan
        FOREIGN KEY (id_pesanan)
        REFERENCES pesanan (id_pesanan)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_detail_pesanan_merchandise
        FOREIGN KEY (id_merchandise)
        REFERENCES merchandise (id_merchandise)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


CREATE TABLE pembayaran (
    id_pembayaran SERIAL PRIMARY KEY,
    id_pesanan INT NOT NULL,
    order_id VARCHAR(100) NOT NULL UNIQUE,
    transaction_id VARCHAR(100) UNIQUE,
    tanggal_pembayaran TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metode_pembayaran VARCHAR(30) NOT NULL,
    jumlah_bayar NUMERIC(12,2) NOT NULL,
    status_bayar VARCHAR(30) NOT NULL,
    jenis_pembayaran VARCHAR(20) NOT NULL,
    payment_url VARCHAR(500),
    CONSTRAINT chk_pembayaran_jumlah_bayar
        CHECK (jumlah_bayar >= 0),
    CONSTRAINT chk_pembayaran_status
        CHECK (
            status_bayar IN (
                'Menunggu',
                'Berhasil',
                'Gagal',
                'Kedaluwarsa'
            )
        ),
    CONSTRAINT chk_pembayaran_jenis
        CHECK (
            jenis_pembayaran IN (
                'Full Payment',
                'DP',
                'Pelunasan'
            )
        ),
    CONSTRAINT fk_pembayaran_pesanan
        FOREIGN KEY (id_pesanan)
        REFERENCES pesanan (id_pesanan)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


CREATE TABLE pengiriman (
    id_pengiriman SERIAL PRIMARY KEY,
    id_pesanan INT NOT NULL UNIQUE,
    -- Nullable: pesanan 'Belum Dikirim' belum tentu punya admin penanggung jawab
    id_admin INT,
    alamat TEXT NOT NULL,
    tanggal_pengiriman TIMESTAMP,
    status_pengiriman VARCHAR(30) NOT NULL,
    bukti_pengiriman VARCHAR(255),
    CONSTRAINT chk_pengiriman_status
        CHECK (
            status_pengiriman IN (
                'Belum Dikirim',
                'Dikirim',
                'Diterima'
            )
        ),
    CONSTRAINT fk_pengiriman_pesanan
        FOREIGN KEY (id_pesanan)
        REFERENCES pesanan (id_pesanan)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_pengiriman_admin
        FOREIGN KEY (id_admin)
        REFERENCES akun_login (id_akun)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


-- =====================================================================
-- Index untuk kolom foreign key (PostgreSQL tidak membuat otomatis)
-- =====================================================================

CREATE INDEX idx_detail_pesanan_merchandise
    ON detail_pesanan (id_merchandise);

CREATE INDEX idx_pembayaran_pesanan
    ON pembayaran (id_pesanan);

CREATE INDEX idx_pesanan_akun
    ON pesanan (id_akun);

CREATE INDEX idx_merchandise_kategori
    ON merchandise (id_kategori);
