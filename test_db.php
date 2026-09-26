<?php

$host = "localhost";
$port = "5432";
$dbname = "merchandise";
$user = "postgres";
$password = "123654";

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password
    );

    echo "Koneksi PostgreSQL berhasil!";
} catch (PDOException $e) {
    echo "Koneksi gagal: " . $e->getMessage();
}