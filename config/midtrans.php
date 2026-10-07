<?php

/**
 * Integrasi Midtrans Snap (fase 4).
 *
 * Konfigurasi .env:
 *   MIDTRANS_SERVER_KEY    (SB-Mid-server-... untuk sandbox)
 *   MIDTRANS_CLIENT_KEY    (SB-Mid-client-...)
 *   MIDTRANS_IS_PRODUCTION (false = sandbox, true = produksi)
 *
 * CATATAN JARINGAN: seluruh request keluar DIPAKSA lewat IPv4
 * (CURLOPT_IPRESOLVE_V4) karena whitelist server hanya menyediakan
 * satu IP IPv4.
 */

const MIDTRANS_STATUS_MAP = [
    "capture"   => "Berhasil",   // kartu; cek fraud_status
    "settlement"=> "Berhasil",
    "deny"      => "Gagal",
    "cancel"    => "Gagal",
    "expire"    => "Kedaluwarsa",
    "pending"   => "Menunggu",
];

function midtrans_terkonfigurasi(): bool
{
    return !empty($_ENV["MIDTRANS_SERVER_KEY"]);
}

function midtrans_base_url(): string
{
    $produksi = strtolower($_ENV["MIDTRANS_IS_PRODUCTION"] ?? "false") === "true";
    return $produksi ? "https://app.midtrans.com" : "https://app.sandbox.midtrans.com";
}

/** Petakan transaction_status + fraud_status Midtrans ke status_bayar kita. */
function midtrans_petakan_status(string $transactionStatus, string $fraudStatus): string
{
    if ($transactionStatus === "capture") {
        return $fraudStatus === "challenge" ? "Menunggu" : "Berhasil";
    }
    return MIDTRANS_STATUS_MAP[$transactionStatus] ?? "Menunggu";
}

/**
 * Request HTTP ke Midtrans (cURL, dipaksa IPv4).
 * Return: [kode_http, body_array]
 */
function midtrans_request(string $method, string $path, ?array $jsonBody = null): array
{
    $ch = curl_init(midtrans_base_url() . $path);

    $headers = [
        "accept: application/json",
        "authorization: Basic " . base64_encode($_ENV["MIDTRANS_SERVER_KEY"] . ":"),
    ];
    if ($jsonBody !== null) {
        $headers[] = "content-type: application/json";
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // paksa IPv4
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [0, ["error_message" => "Koneksi ke Midtrans gagal: " . $err]];
    }

    $data = json_decode($body, true);
    return [$kode, is_array($data) ? $data : ["raw" => $body]];
}

/**
 * Buat transaksi Snap untuk satu tagihan + catat attempt ke tabel pembayaran.
 * Setiap attempt = satu baris pembayaran (order_id unik per attempt).
 *
 * $jenis: 'Full Payment' | 'DP' | 'Pelunasan'
 * Return: [ok(bool), payment_url | pesan error]
 */
function midtrans_buat_pembayaran(PDO $pdo, int $idPesanan, float $gross, string $jenis): array
{
    if (!midtrans_terkonfigurasi()) {
        return [false, "Pembayaran online belum dikonfigurasi di server."];
    }

    // Data customer (email/nama dari database, bukan session)
    $stmt = $pdo->prepare("
        SELECT a.email, COALESCE(m.nama, a.username) AS nama
        FROM akun_login a
        LEFT JOIN mahasiswa m ON m.id_akun = a.id_akun
        WHERE a.id_akun = :id
    ");
    $stmt->execute([":id" => $_SESSION["id_akun"]]);
    $cust = $stmt->fetch() ?: ["email" => "", "nama" => "Pelanggan"];

    $orderId = "PBL-" . str_pad((string)$idPesanan, 5, "0", STR_PAD_LEFT) . "-" . time();

    $customer = [
        "first_name" => $cust["nama"] ?: ($_SESSION["username"] ?? "Pelanggan"),
    ];
    if (!empty($cust["email"])) {
        $customer["email"] = $cust["email"];
    }

    $payload = [
        "transaction_details" => [
            "order_id"     => $orderId,
            "gross_amount" => number_format($total, 2, ".", ""),
        ],
        "customer_details" => $customer,
    ];

    // Setelah selesai/pending/gagal, kembalikan pengguna ke halaman pesanan.
    // Midtrans menambahkan parameter order_id & transaction_status — aman diabaikan.
    if (!empty($_ENV["APP_URL"])) {
        $kembali = rtrim($_ENV["APP_URL"], "/") . "/mahasiswa/pesanan.php?id=" . $idPesanan;
        $payload["callbacks"] = [
            "finish"   => $kembali,
            "unfinish" => $kembali,
            "error"    => $kembali,
        ];
    }

    [$kode, $data] = midtrans_request("POST", "/snap/v1/transactions", $payload);

    if ($kode !== 201 || empty($data["redirect_url"])) {
        error_log("Midtrans Snap gagal (HTTP {$kode}): " . json_encode($data));
        return [false, "Gagal membuat transaksi pembayaran. Coba beberapa saat lagi."];
    }

    // Catat attempt pembayaran
    $stmt = $pdo->prepare("
        INSERT INTO pembayaran
            (id_pesanan, order_id, metode_pembayaran, jumlah_bayar,
             status_bayar, jenis_pembayaran, payment_url)
        VALUES
            (:id_pesanan, :order_id, 'Midtrans Snap', :jumlah,
             'Menunggu', :jenis, :payment_url)
    ");
    $stmt->execute([
        ":id_pesanan"  => $idPesanan,
        ":order_id"    => $orderId,
        ":jumlah"      => number_format($gross, 2, ".", ""),
        ":jenis"       => $jenis,
        ":payment_url" => $data["redirect_url"],
    ]);

    return [true, $data["redirect_url"]];
}
