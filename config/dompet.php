<?php

/**
 * Dompet (wallet) mahasiswa — helper sisi aplikasi.
 * Logika saldo (potong, kembalikan, refund) ada di function database
 * (database/dompet.sql); file ini hanya memanggilnya.
 *
 * Butuh: config/midtrans.php (untuk top up & bayar sebagian).
 */

const TOPUP_MINIMAL = 10000;
const TOPUP_MAKSIMAL = 5000000;

function dompet_saldo(PDO $pdo, int $idAkun): float
{
    $stmt = $pdo->prepare("SELECT saldo FROM dompet WHERE id_akun = :id");
    $stmt->execute([":id" => $idAkun]);
    return (float)($stmt->fetchColumn() ?: 0);
}

/**
 * Bayar tagihan satu pesanan. Bila $pakaiSaldo, saldo dompet dipakai lebih dulu:
 *   - saldo >= tagihan → lunas dari dompet (tanpa Midtrans)
 *   - saldo <  tagihan → saldo ditahan, sisanya lewat Midtrans
 * Return: [ok(bool), tipe('lunas'|'midtrans'), url_midtrans | pesan error]
 */
function dompet_bayar_pesanan(PDO $pdo, int $idPesanan, float $gross, string $jenis, bool $pakaiSaldo): array
{
    $idAkun     = (int)$_SESSION["id_akun"];
    $saldoPakai = $pakaiSaldo ? min(dompet_saldo($pdo, $idAkun), $gross) : 0.0;

    if ($saldoPakai <= 0) {
        [$ok, $hasil] = midtrans_buat_pembayaran($pdo, $idPesanan, $gross, $jenis);
        return [$ok, "midtrans", $hasil];
    }

    if ($saldoPakai < $gross) {
        [$ok, $hasil] = midtrans_buat_pembayaran($pdo, $idPesanan, $gross, $jenis, $saldoPakai);
        return [$ok, "midtrans", $hasil];
    }

    // Lunas dari dompet
    $orderId = "PBL-" . str_pad((string)$idPesanan, 5, "0", STR_PAD_LEFT) . "-" . time() . "-D";

    try {
        $stmt = $pdo->prepare("
            SELECT fn_bayar_dompet(
                :akun, :pesanan, :jenis,
                CAST(:gross AS NUMERIC), CAST(:pakai AS NUMERIC), :order_id, NULL
            )
        ");
        $stmt->execute([
            ":akun"     => $idAkun,
            ":pesanan"  => $idPesanan,
            ":jenis"    => $jenis,
            ":gross"    => number_format($gross, 2, ".", ""),
            ":pakai"    => number_format($saldoPakai, 2, ".", ""),
            ":order_id" => $orderId,
        ]);
        return [true, "lunas", ""];
    } catch (PDOException $e) {
        error_log("Bayar dompet gagal: " . $e->getMessage());
        return [false, "lunas", pesan_error_dompet($e)];
    }
}

/** Pesan ramah dari exception function dompet (selain itu: pesan umum). */
function pesan_error_dompet(PDOException $e): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, "Saldo dompet tidak cukup")) {
        return "Saldo dompet tidak cukup.";
    }
    if (str_contains($msg, "tidak dapat dibayar pada status")) {
        return "Pesanan ini tidak dapat dibayar pada statusnya saat ini.";
    }
    return "Terjadi kesalahan database. Coba lagi.";
}

/**
 * Buat transaksi Snap untuk top up + catat ke topup_dompet.
 * Return: [ok(bool), payment_url | pesan error]
 */
function midtrans_buat_topup(PDO $pdo, int $idAkun, int $jumlah): array
{
    if (!midtrans_terkonfigurasi()) {
        return [false, "Pembayaran online belum dikonfigurasi di server."];
    }

    $stmt = $pdo->prepare("
        SELECT a.email, COALESCE(m.nama, a.username) AS nama
        FROM akun_login a
        LEFT JOIN mahasiswa m ON m.id_akun = a.id_akun
        WHERE a.id_akun = :id
    ");
    $stmt->execute([":id" => $idAkun]);
    $cust = $stmt->fetch() ?: ["email" => "", "nama" => "Pelanggan"];

    $orderId = "TOPUP-" . str_pad((string)$idAkun, 5, "0", STR_PAD_LEFT) . "-" . time();

    $customer = ["first_name" => $cust["nama"] ?: "Pelanggan"];
    if (!empty($cust["email"])) {
        $customer["email"] = $cust["email"];
    }

    $payload = [
        "transaction_details" => [
            "order_id"     => $orderId,
            "gross_amount" => number_format($jumlah, 2, ".", ""),
        ],
        "customer_details" => $customer,
        "item_details"     => [[
            "id"       => "TOPUP",
            "price"    => $jumlah,
            "quantity" => 1,
            "name"     => "Top up dompet",
        ]],
        "expiry" => ["unit" => "minutes", "duration" => 60],
    ];

    if (!empty($_ENV["APP_URL"])) {
        $kembali = rtrim($_ENV["APP_URL"], "/") . "/mahasiswa/dompet.php";
        $payload["callbacks"] = ["finish" => $kembali, "unfinish" => $kembali, "error" => $kembali];
    }

    [$kode, $data] = midtrans_request("POST", "/snap/v1/transactions", $payload);

    if ($kode !== 201 || empty($data["redirect_url"])) {
        error_log("Midtrans Snap top up gagal (HTTP {$kode}): " . json_encode($data));
        return [false, "Gagal membuat transaksi top up. Coba beberapa saat lagi."];
    }

    $stmt = $pdo->prepare("
        INSERT INTO topup_dompet (id_akun, order_id, jumlah, status, metode, payment_url)
        VALUES (:akun, :order_id, :jumlah, 'Menunggu', 'Midtrans Snap', :url)
    ");
    $stmt->execute([
        ":akun"     => $idAkun,
        ":order_id" => $orderId,
        ":jumlah"   => number_format($jumlah, 2, ".", ""),
        ":url"      => $data["redirect_url"],
    ]);

    return [true, $data["redirect_url"]];
}
