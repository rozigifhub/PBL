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
$stmt = $pdo->prepare("SELECT id_pesanan FROM pembayaran WHERE order_id = :order_id");
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

// 4. Perbarui attempt pembayaran
$stmt = $pdo->prepare("
    UPDATE pembayaran
    SET status_bayar = :status, transaction_id = :tid
    WHERE order_id = :order_id
");
$stmt->execute([
    ":status"   => $statusBayar,
    ":tid"      => $transactionId,
    ":order_id" => $order_id,
]);

// 5. Pembayaran berhasil → pesanan masuk proses
if ($statusBayar === "Berhasil") {
    $stmt = $pdo->prepare("
        UPDATE pesanan
        SET status_pesanan = 'Diproses'
        WHERE id_pesanan = :id
          AND status_pesanan = 'Menunggu Pembayaran'
    ");
    $stmt->execute([":id" => $idPesanan]);
}

error_log("Webhook Midtrans: {$order_id} → {$transaction} ({$statusBayar})");

echo json_encode(["status" => "ok"]);
