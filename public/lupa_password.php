<?php

session_start();

require_once "../config/database.php";
require_once "../config/mailer.php";

// Token CSRF untuk form
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$suksesTampil = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrfToken = $_POST["csrf_token"] ?? "";
    $email = strtolower(trim($_POST["email"] ?? ""));

    $csrfValid = isset($_SESSION["csrf_token"])
        && is_string($csrfToken)
        && hash_equals($_SESSION["csrf_token"], $csrfToken);

    if (!$csrfValid) {
        $error = "Sesi tidak valid. Silakan muat ulang halaman.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } else {

        try {
            // Rate limit sederhana: maksimal 1 permintaan per email per 2 menit
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM reset_password
                WHERE id_akun = (SELECT id_akun FROM akun_login WHERE email = :email)
                  AND created_at > now() - INTERVAL '2 minutes'
            ");
            $stmt->execute([":email" => $email]);

            if ($stmt->fetchColumn() > 0) {
                $error = "Permintaan reset sudah dibuat baru-baru ini. Coba lagi beberapa menit lagi.";
            } else {
                $stmt = $pdo->prepare("
                    SELECT id_akun, username
                    FROM akun_login
                    WHERE email = :email
                ");
                $stmt->execute([":email" => $email]);
                $akun = $stmt->fetch();

                if ($akun) {
                    // Token acak 256-bit; yang disimpan di DB hanya hash SHA-256-nya
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash("sha256", $token);

                    $stmt = $pdo->prepare("
                        INSERT INTO reset_password (id_akun, token_hash, expires_at)
                        VALUES (:id_akun, :token_hash, now() + INTERVAL '60 minutes')
                    ");
                    $stmt->execute([
                        ":id_akun"    => $akun["id_akun"],
                        ":token_hash" => $tokenHash,
                    ]);

                    // Tautan absolut: pakai APP_URL dari .env, atau host dari request
                    $base = ($_ENV["APP_URL"] ?? "") !== ""
                        ? $_ENV["APP_URL"]
                        : ((!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http")
                          . "://" . ($_SERVER["HTTP_HOST"] ?? "localhost");

                    if (!send_reset_email($email, $akun["username"], $base . "/reset_password.php?token=" . $token)) {
                        $error = "Gagal mengirim email. Silakan coba lagi nanti.";
                    }
                }

                // Email tidak terdaftar: sengaja tidak melakukan apa pun.
                // Pesan yang tampil tetap sama (anti email-enumeration).
                if ($error === "") {
                    $suksesTampil = true;
                }
            }
        } catch (PDOException $e) {
            error_log("Lupa password gagal: " . $e->getMessage());
            $error = "Terjadi kesalahan. Silakan coba lagi nanti.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Lupa Password - Merchandise Kampus</title>
</head>
<body>

    <h1>Lupa Password</h1>

    <?php if ($suksesTampil): ?>

        <p>
            Jika email terdaftar, tautan reset telah dikirim.
            Silakan cek inbox atau folder spam (berlaku 60 menit).
        </p>
        <a href="login.php">Kembali ke Login</a>

    <?php else: ?>

        <?php if ($error !== ""): ?>
            <p><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

            <label>Email terdaftar</label><br>
            <input type="email" name="email" required>

            <br><br>
            <button type="submit">Kirim Tautan Reset</button>
        </form>

        <p>
            <a href="login.php">Kembali ke Login</a>
        </p>

    <?php endif; ?>

</body>
</html>
