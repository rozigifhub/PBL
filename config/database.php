<?php

$envFile = dirname(__DIR__) . '/.env';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $_ENV[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1), " \t\"'");
    }
}

// Pastikan semua konfigurasi wajib tersedia
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
    if (empty($_ENV[$key])) {
        http_response_code(500);
        exit("Konfigurasi database tidak lengkap: {$key} tidak ditemukan.");
    }
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'],
    $_ENV['DB_NAME']
);

try {
    // Koneksi persisten: tiap worker PHP-FPM memakai ulang koneksinya
    // (membuka koneksi Postgres baru ~25 ms per request di server 1 core).
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT         => true,
    ]);

    // Koneksi dipakai bergantian antar request: transaksi yang tertinggal dibatalkan
    // (pengecekan lokal, tanpa round trip ke database). Identitas aktor audit memakai
    // set_config(..., true) yang otomatis hilang di akhir transaksi; satu-satunya
    // pengaturan tingkat-sesi (webhook Midtrans) mereset dirinya sendiri di akhir request.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Prepared statement asli = 2 round trip per query (Parse lalu Bind/Execute).
    // Emulasi = 1 round trip. Nyalakan dengan DB_EMULATE_PREPARES=true di .env, lalu
    // restart php-fpm. Default mati (perilaku lama) sampai diuji di server.
    $pdo->setAttribute(
        PDO::ATTR_EMULATE_PREPARES,
        strtolower($_ENV['DB_EMULATE_PREPARES'] ?? 'false') === 'true'
    );
} catch (PDOException $e) {
    error_log('Koneksi database gagal: ' . $e->getMessage());
    http_response_code(500);
    exit('Koneksi database gagal.');
}
