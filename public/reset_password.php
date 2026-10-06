<?php

session_start();

require_once "../config/database.php";

// Token CSRF untuk form
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$sukses = false;
$tokenOK = false;
$token = "";

// Cari token yang masih berlaku (belum dipakai & belum kedaluwarsa)
function cariResetValid(PDO $pdo, string $token): ?array
{
    if ($token === "") {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id_reset, id_akun
        FROM reset_password
        WHERE token_hash = :token_hash
          AND used_at IS NULL
          AND expires_at > now()
    ");
    $stmt->execute([":token_hash" => hash("sha256", $token)]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrfToken  = $_POST["csrf_token"] ?? "";
    $token      = $_POST["token"] ?? "";
    $password   = $_POST["password"] ?? "";
    $konfirmasi = $_POST["password_konfirmasi"] ?? "";

    $csrfValid = isset($_SESSION["csrf_token"])
        && is_string($csrfToken)
        && hash_equals($_SESSION["csrf_token"], $csrfToken);

    if (!$csrfValid) {
        $error = "Sesi tidak valid. Silakan muat ulang halaman.";
    } elseif (($reset = cariResetValid($pdo, $token)) === null) {
        $error = "Tautan reset tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.";
    } else {
        $tokenOK = true;

        if (strlen($password) < 8) {
            $error = "Password minimal 8 karakter.";
        } elseif ($password !== $konfirmasi) {
            $error = "Konfirmasi password tidak cocok.";
        } else {
            try {
                $pdo->beginTransaction();

                // Ubah password
                $stmt = $pdo->prepare("
                    UPDATE akun_login
                    SET password = :password
                    WHERE id_akun = :id_akun
                ");
                $stmt->execute([
                    ":password" => password_hash($password, PASSWORD_DEFAULT),
                    ":id_akun"  => $reset["id_akun"],
                ]);

                // Hanguskan SEMUA token reset milik akun ini (termasuk token ini)
                $stmt = $pdo->prepare("
                    UPDATE reset_password
                    SET used_at = now()
                    WHERE id_akun = :id_akun AND used_at IS NULL
                ");
                $stmt->execute([":id_akun" => $reset["id_akun"]]);

                $pdo->commit();

                $sukses = true;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Reset password gagal: " . $e->getMessage());
                $error = "Terjadi kesalahan. Silakan coba lagi.";
            }
        }
    }
} else {
    // GET: validasi token sebelum menampilkan form
    $token = $_GET["token"] ?? "";

    if (cariResetValid($pdo, $token) !== null) {
        $tokenOK = true;
    } else {
        $error = "Tautan reset tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.";
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - Merchandise Kampus</title>
</head>
<body>

    <h1>Reset Password</h1>

    <?php if ($sukses): ?>

        <p>Password berhasil diubah. Silakan login dengan password baru.</p>
        <a href="login.php">Login</a>

    <?php elseif ($tokenOK): ?>

        <?php if ($error !== ""): ?>
            <p><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <label>Password Baru</label><br>
            <input type="password" name="password" required minlength="8">

            <br><br>

            <label>Konfirmasi Password Baru</label><br>
            <input type="password" name="password_konfirmasi" required>

            <br><br>
            <button type="submit">Simpan Password Baru</button>
        </form>

    <?php else: ?>

        <p><?= htmlspecialchars($error) ?></p>
        <a href="lupa_password.php">Minta tautan baru</a>

    <?php endif; ?>

</body>
</html>
