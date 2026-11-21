<?php

require_once __DIR__ . "/../config/functions.php";
app_session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/mailer.php";

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
<?php
require_once __DIR__ . "/includes/components.php";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Password - Merchandise Kampus</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . "/assets/css/style.css") ?>">
    <link rel="stylesheet" href="assets/css/navbar-mahasiswa.css?v=<?= @filemtime(__DIR__ . "/assets/css/navbar-mahasiswa.css") ?>">
    <link rel="stylesheet" href="assets/css/lupa-password.css?v=<?= @filemtime(__DIR__ . "/assets/css/lupa-password.css") ?>">
    <script src="assets/js/auth.js" defer></script>
</head>
<body class="page-lupa">

<?php require __DIR__ . "/includes/navbar.php"; ?>

<main class="auth">
    <section class="card">
        <img class="card__logo" src="assets/img/logo-jti.webp" alt="Logo JTI">

        <?php if ($suksesTampil): ?>

            <!-- TAMPILAN SETELAH PERMINTAAN BERHASIL (logika $suksesTampil tidak diubah) -->
            <div class="form lupa lupa--sukses">
                <div class="lupa__icon" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6.5" width="26" height="19" rx="3"/><path d="M4.5 10 16 18.5 27.5 10"/></svg>
                </div>

                <h2 class="form__title">Cek Email Anda</h2>
                <p class="lupa__desc">
                    Jika email terdaftar, tautan reset telah dikirim.
                    Silakan cek inbox atau folder spam (berlaku <strong>60 menit</strong>).
                </p>

                <a class="btn" href="login.php"><span>Kembali ke Login</span><?= icon("arrow") ?></a>
            </div>

        <?php else: ?>

            <form class="form" method="POST" data-auth>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                <?php if ($error !== ""): ?>
                    <div class="alert alert--error" role="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <h2 class="form__title">Lupa Password</h2>
                <p class="lupa__desc">
                    Masukkan email yang terdaftar. Kami akan mengirimkan tautan untuk mengatur ulang password Anda.
                </p>

                <?php
                // Nama field (parameter ke-2) harus sama dengan $_POST["email"] di bagian atas file ini
                field("Email terdaftar", "email", "ENTER EMAIL", ["type" => "email", "icon" => "mail", "value" => $email ?? "", "attrs" => 'autocomplete="email"']);
                ?>

                <button class="btn" type="submit">
                    <span>Kirim Tautan Reset</span><?= icon("arrow") ?><span class="spinner"></span>
                </button>
            </form>

            <p class="switch"><a href="login.php">Kembali ke Login</a></p>

        <?php endif; ?>
    </section>
</main>

</body>
</html>
