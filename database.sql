CREATE DATABASE merchandise;

CREATE TABLE akun_login (
    id_akun SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    CONSTRAINT chk_akun_login_role
        CHECK (role IN ('Admin', 'Mahasiswa'))
);

CREATE TABLE mahasiswa (
    id_mahasiswa SERIAL PRIMARY KEY,
    id_akun INT NOT NULL UNIQUE,
    nim VARCHAR(20) NOT NULL UNIQUE,
    nama VARCHAR(100) NOT NULL,
    no_hp VARCHAR(20) NOT NULL,
    CONSTRAINT fk_mahasiswa_akun_login
        FOREIGN KEY (id_akun)
        REFERENCES akun_login (id_akun)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE kategori_merchandise (
    id_kategori SERIAL PRIMARY KEY,
    nama_kategori VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE merchandise (
    id_merchandise SERIAL PRIMARY KEY,
    id_kategori INT NOT NULL,
    nama_merchandise VARCHAR(100) NOT NULL,
    ukuran VARCHAR(10) NOT NULL,
    harga NUMERIC(12,2) NOT NULL,
    stok INT NOT NULL,
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
    id_mahasiswa INT NOT NULL,
    tanggal_pesanan TIMESTAMP NOT NULL,
    total_harga NUMERIC(12,2) NOT NULL,
    status_pesanan VARCHAR(30) NOT NULL,
    CONSTRAINT chk_pesanan_total_harga
        CHECK (total_harga > 0),
    CONSTRAINT fk_pesanan_mahasiswa
        FOREIGN KEY (id_mahasiswa)
        REFERENCES mahasiswa (id_mahasiswa)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE detail_pesanan (
    id_detail SERIAL PRIMARY KEY,
    id_pesanan INT NOT NULL,
    id_merchandise INT NOT NULL,
    jumlah INT NOT NULL,
    subtotal NUMERIC(12,2) NOT NULL,
    CONSTRAINT uq_detail_pesanan_pesanan_merchandise
        UNIQUE (id_pesanan, id_merchandise),
    CONSTRAINT chk_detail_pesanan_jumlah
        CHECK (jumlah > 0),
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
    id_pesanan INT NOT NULL UNIQUE,
    tanggal_pembayaran TIMESTAMP NOT NULL,
    metode_pembayaran VARCHAR(30) NOT NULL,
    jumlah_bayar NUMERIC(12,2) NOT NULL,
    status_bayar VARCHAR(30) NOT NULL,
    CONSTRAINT chk_pembayaran_jumlah_bayar
        CHECK (jumlah_bayar >= 0),
    CONSTRAINT fk_pembayaran_pesanan
        FOREIGN KEY (id_pesanan)
        REFERENCES pesanan (id_pesanan)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE pengiriman (
    id_pengiriman SERIAL PRIMARY KEY,
    id_pesanan INT NOT NULL UNIQUE,
    alamat VARCHAR(255) NOT NULL,
    tanggal_pengiriman TIMESTAMP,
    status_pengiriman VARCHAR(30) NOT NULL,
    CONSTRAINT fk_pengiriman_pesanan
        FOREIGN KEY (id_pesanan)
        REFERENCES pesanan (id_pesanan)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

ALTER TABLE pembayaran
ADD COLUMN payment_reference VARCHAR(100),
ADD COLUMN payment_url VARCHAR(500);

ALTER TABLE pembayaran
DROP CONSTRAINT pembayaran_id_pesanan_key;