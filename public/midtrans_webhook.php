<?php

/**
 * Webhook Payment Notification Midtrans (HTTP POST JSON).
 *
 * Daftarkan URL ini di dashboard Midtrans:
 *   Settings > Configuration > Payment Notification URL
 *   contoh: http://202.155.16.144/midtrans_webhook.php
 *
 * Keamanan: verifikasi signature_key =
 *   sha512(order_id + status_code + gross_amount + server_key)
 * Tanpa signature valid, notifikasi diabaikan (403).
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/midtrans.php";

$notif = json_decode(file_get_contents("php://input"), true);

if (!is_array($notif)) {
    http_response_code(400);
    exit;
}

$order_id     = (string)($notif["order_id"] ?? "");
$status_code  = (string)($notif["status_code"] ?? "");
$gross_amount = (string)($notif["gross_amount"] ?? "");
$signature    = (string)($notif["signature_key"] ?? "");
$transaction  = (string)($notif["transaction_status"] ?? "");
$fraud        = (string)($notif["fraud_status"] ?? "");
$transactionId= (string)($notif["transaction_id"] ?? "");

// 1. Verifikasi tanda tangan
$diharapkan = hash("sha512", $order_id . $status_code . $gross_amount . $_ENV["MIDTRANS_SERVER_KEY"]);
if ($order_id === "" || !hash_equals($diharapkan, $signature)) {
    error_log("Webhook Midtrans: signature tidak valid untuk {$order_id}");
    http_response_code(403);
    exit;
}

// 2. Cari attempt pembayaran berdasarkan order_id
$stmt = $pdo->prepare("
    SELECT id_pesanan, jenis_pembayaran
    FROM pembayaran
    WHERE order_id = :order_id
");
$stmt->execute([":order_id" => $order_id]);
$baris = $stmt->fetch();

if ($baris === false) {
    // Balas 200 agar Midtrans tidak terus-menerus mengulang notifikasi
    echo json_encode(["status" => "ignored", "reason" => "order_id tidak dikenal"]);
    exit;
}

$idPesanan = (int)$baris["id_pesanan"];

// 3. Petakan status Midtrans → status_bayar kita
$statusBayar = midtrans_petakan_status($transaction, $fraud);

// Metode pembayaran asli yang dipilih pengguna (gopay, bank_transfer, qris, ...)
$paymentType = (string)($notif["payment_type"] ?? "");

// 4. Perbarui attempt pembayaran
$stmt = $pdo->prepare("
    UPDATE pembayaran
    SET status_bayar = :status, transaction_id = :tid, metode_pembayaran = :metode
    WHERE order_id = :order_id
");
$stmt->execute([
    ":status"   => $statusBayar,
    ":tid"      => $transactionId,
    ":metode"   => $paymentType !== "" ? $paymentType : "Midtrans Snap",
    ":order_id" => $order_id,
]);

// 5. Pembayaran berhasil → status pesanan mengikuti jenis pembayaran:
//    Full Payment → Lunas | DP → DP Lunas | Pelunasan → Lunas
//    ('Diproses' dan seterusnya menjadi wewenang admin — fase 5)
if ($statusBayar === "Berhasil") {
    $statusPesananBaru = $jenis === "DP" ? "DP Lunas" : "Lunas";

    $stmt = $pdo->prepare("
        UPDATE pesanan
        SET status_pesanan = :status
        WHERE id_pesanan = :id
          AND status_pesanan IN
              ('Menunggu Pembayaran', 'Menunggu DP', 'DP Lunas', 'Menunggu Pelunasan')
    ");
    $stmt->execute([":status" => $statusPesananBaru, ":id" => $idPesanan]);
}

error_log("Webhook Midtrans: {$order_id} → {$transaction} ({$statusBayar})");

echo json_encode(["status" => "ok"]);
