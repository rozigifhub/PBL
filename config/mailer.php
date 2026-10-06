<?php

/**
 * Pengirim email reset password via SMTP (Brevo).
 *
 * Kredensial dibaca dari .env:
 *   SMTP_HOST  (mis. smtp-relay.brevo.com)
 *   SMTP_PORT  (mis. 587)
 *   SMTP_SECURE ("tls" untuk port 587, "ssl" untuk port 465)
 *   SMTP_USER  (email akun Brevo)
 *   SMTP_PASS  (SMTP key Brevo, diawali "xkeysib-")
 *   MAIL_FROM  (email pengirim: email akun Brevo atau sender terverifikasi)
 *
 * Mode pengembangan: jika SMTP_USER kosong, email TIDAK dikirim.
 * Tautan reset dicatat ke mail_log.txt di folder project
 * (folder ini tidak dilayani nginx, jadi aman).
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

function send_reset_email(string $toEmail, string $username, string $resetLink): bool
{
    $body = "Halo {$username},\n\n"
          . "Kami menerima permintaan reset password untuk akun kamu.\n"
          . "Klik tautan berikut untuk membuat password baru (berlaku 60 menit):\n\n"
          . $resetLink . "\n\n"
          . "Jika kamu tidak merasa meminta reset password, abaikan email ini.\n";

    // Mode pengembangan: tanpa SMTP_USER, catat tautan ke file log
    if (empty($_ENV["SMTP_USER"])) {
        $logFile = dirname(__DIR__) . "/mail_log.txt";
        file_put_contents(
            $logFile,
            date("Y-m-d H:i:s") . " | TO: {$toEmail} | LINK: {$resetLink}" . PHP_EOL,
            FILE_APPEND
        );
        return true;
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log("PHPMailer tidak ditemukan. Jalankan: composer require phpmailer/phpmailer");
        return false;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV["SMTP_HOST"];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV["SMTP_USER"];
        $mail->Password   = $_ENV["SMTP_PASS"];
        $mail->SMTPSecure = ($_ENV["SMTP_SECURE"] ?? "tls") === "ssl"
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($_ENV["SMTP_PORT"] ?? 587);
        $mail->CharSet    = "UTF-8";

        $mail->setFrom($_ENV["MAIL_FROM"] ?? $_ENV["SMTP_USER"], "Merchandise Kampus");
        $mail->addAddress($toEmail, $username);

        $mail->Subject = "Reset Password - Merchandise Kampus";
        $mail->Body    = $body;

        return $mail->send();
    } catch (Throwable $e) {
        error_log("Gagal kirim email reset: " . $e->getMessage());
        return false;
    }
}
