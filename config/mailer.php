<?php

/**
 * Pengirim email reset password via Brevo API (HTTPS, port 443).
 *
 * Tidak memakai SMTP lagi — cukup API key Brevo (diawali "xkeysib-")
 * yang dibaca dari .env:
 *   SMTP_PASS  = API key Brevo   (wajib)
 *   MAIL_FROM  = email pengirim  (default: email akun Brevo)
 *
 * Mode pengembangan: jika SMTP_PASS kosong, email TIDAK dikirim.
 * Tautan reset dicatat ke mail_log.txt di folder project
 * (folder ini tidak dilayani nginx, jadi aman).
 */

function send_reset_email(string $toEmail, string $username, string $resetLink): bool
{
    $body = "Halo {$username},\n\n"
          . "Kami menerima permintaan reset password untuk akun kamu.\n"
          . "Klik tautan berikut untuk membuat password baru (berlaku 60 menit):\n\n"
          . $resetLink . "\n\n"
          . "Jika kamu tidak merasa meminta reset password, abaikan email ini.\n";

    // Mode pengembangan: tanpa API key, catat tautan ke file log
    if (empty($_ENV["SMTP_PASS"])) {
        $logFile = dirname(__DIR__) . "/mail_log.txt";
        file_put_contents(
            $logFile,
            date("Y-m-d H:i:s") . " | TO: {$toEmail} | LINK: {$resetLink}" . PHP_EOL,
            FILE_APPEND
        );
        return true;
    }

    $payload = json_encode([
        "sender" => [
            "name"  => "Merchandise Kampus",
            "email" => $_ENV["MAIL_FROM"] ?? $_ENV["SMTP_USER"],
        ],
        "to" => [
            ["email" => $toEmail, "name" => $username],
        ],
        "subject"     => "Reset Password - Merchandise Kampus",
        "textContent" => $body,
    ]);

    $context = stream_context_create(["http" => [
        "method"        => "POST",
        "header"        => "accept: application/json\r\n"
                         . "content-type: application/json\r\n"
                         . "api-key: " . $_ENV["SMTP_PASS"] . "\r\n",
        "content"       => $payload,
        "timeout"       => 15,
        "ignore_errors" => true,
    ]]);

    $response = @file_get_contents("https://api.brevo.com/v3/smtp/email", false, $context);

    if ($response === false) {
        error_log("Gagal kirim email reset: API Brevo tidak terjangkau.");
        return false;
    }

    // Ambil kode HTTP dari response header terakhir
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($status === 201) {
        return true;
    }

    // Catat alasan penolakan dari Brevo agar mudah didiagnosis
    error_log("Brevo API menolak email (HTTP {$status}): " . substr($response, 0, 300));
    return false;
}
