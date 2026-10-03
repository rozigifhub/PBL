<?php

session_start();

require_once "../config/database.php";

// Token CSRF untuk form
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrfToken = $_POST["csrf_token"] ?? "";

    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    $nim = trim($_POST["nim"] ?? "");
    $nama = trim($_POST["nama"] ?? "");
    $no_hp = trim($_POST["no_hp"] ?? "");

    $csrfValid = isset($_SESSION["csrf_token"])
        && is_string($csrfToken)
        && hash_equals($_SESSION["csrf_token"], $csrfToken);

    if (
        $username === "" ||
        $email === "" ||
        $password === "" ||
        $nim === "" ||
        $nama === "" ||
        $no_hp === ""
    ) {
        $error = "Semua data wajib diisi.";
    } elseif (!$csrfValid) {
        $error = "Sesi tidak valid. Silakan muat ulang halaman.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } elseif (strlen($password) < 8) {
        $error = "Password minimal 8 karakter.";
    } else {

        // Normalisasi email ke huruf kecil agar konsisten saat login
        $email = strtolower($email);

        try {

            $pdo->beginTransaction();

            // Cek username, email, dan NIM
            $stmt = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM akun_login WHERE username = :username) AS username_count,
                    (SELECT COUNT(*) FROM akun_login WHERE email = :email) AS email_count,
                    (SELECT COUNT(*) FROM mahasiswa WHERE nim = :nim) AS nim_count
            ");

            $stmt->execute([
                ":username" => $username,
                ":email" => $email,
                ":nim" => $nim
            ]);

            $check = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($check["username_count"] > 0) {
                throw new Exception("Username sudah digunakan.");
            }

            if ($check["email_count"] > 0) {
                throw new Exception("Email sudah digunakan.");
            }

            if ($check["nim_count"] > 0) {
                throw new Exception("NIM sudah terdaftar.");
            }

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert akun
            $stmt = $pdo->prepare("
                INSERT INTO akun_login
                    (username, email, password, role)
                VALUES
                    (:username, :email, :password, 'Mahasiswa')
                RETURNING id_akun
            ");

            $stmt->execute([
                ":username" => $username,
                ":email" => $email,
                ":password" => $hashedPassword
            ]);

            $id_akun = $stmt->fetchColumn();

            // Insert data mahasiswa
            $stmt = $pdo->prepare("
                INSERT INTO mahasiswa
                    (id_akun, nim, nama, no_hp)
                VALUES
                    (:id_akun, :nim, :nama, :no_hp)
            ");

            $stmt->execute([
                ":id_akun" => $id_akun,
                ":nim" => $nim,
                ":nama" => $nama,
                ":no_hp" => $no_hp
            ]);

            $pdo->commit();

            header("Location: login.php?register=success");
            exit;

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Pesan asli hanya dicatat di log, jangan ditampilkan ke user
            error_log("Register gagal: " . $e->getMessage());
            $error = "Registrasi gagal. Coba beberapa saat lagi.";

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
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
    <title>Register - Merchandise Kampus</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/auth.js" defer></script>
</head>
<body class="page-register">

<?php require __DIR__ . "/includes/navbar.php"; ?>

<main class="auth">
    <section class="card">
        <img class="card__logo" src="assets/img/logo-jti.png" alt="Logo JTI">

        <form class="form" method="POST" data-auth>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <h2 class="form__title">Registrasi</h2>
            <?php
            // Nama field (parameter ke-2) harus sama dengan $_POST[...] di register.php
            field("Username", "username", "ENTER USERNAME", ["icon" => "user", "value" => $username ?? "", "attrs" => 'autocomplete="username"']);
            field("Email", "email", "ENTER EMAIL", ["type" => "email", "value" => $email ?? "", "attrs" => 'autocomplete="email"']);
            field("Password", "password", "ENTER PASSWORD", ["type" => "password", "icon" => "lock", "attrs" => 'minlength="8" autocomplete="new-password"']);
            ?>

            <h2 class="form__title">Data Mahasiswa</h2>
            <?php
            field("NIM", "nim", "ENTER NIM", ["value" => $nim ?? "", "attrs" => 'inputmode="numeric"']);
            field("Nama Lengkap", "nama", "ENTER NAMA", ["value" => $nama ?? "", "attrs" => 'autocomplete="name"']);
            field("No. HP", "no_hp", "ENTER NO. HP", ["type" => "tel", "value" => $no_hp ?? "", "attrs" => 'autocomplete="tel"']);
            ?>

            <button class="btn" type="submit">
                <span>Submit</span><?= icon("arrow") ?><span class="spinner"></span>
            </button>
        </form>

        <p class="switch">Sudah punya akun? <a href="login.php">Login</a></p>
    </section>
</main>

</body>
</html>
