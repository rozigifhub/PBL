<?php

session_start();

require_once "../config/database.php";

// Token CSRF untuk form
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$success = "";

if (isset($_GET["register"]) && $_GET["register"] === "success") {
    $success = "Registrasi berhasil. Silakan login.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrfToken = $_POST["csrf_token"] ?? "";
    $login = trim($_POST["login"] ?? "");
    $password = $_POST["password"] ?? "";

    $csrfValid = isset($_SESSION["csrf_token"])
        && is_string($csrfToken)
        && hash_equals($_SESSION["csrf_token"], $csrfToken);

    if (!$csrfValid) {

        $error = "Sesi tidak valid. Silakan muat ulang halaman.";

    } elseif ($login === "" || $password === "") {

        $error = "Username/email dan password wajib diisi.";

    } else {

        $stmt = $pdo->prepare("
            SELECT
                id_akun,
                username,
                email,
                password,
                role
            FROM akun_login
            WHERE username = :login
               OR email = LOWER(:login)
        ");

        $stmt->execute([
            ":login" => $login
        ]);

        $akun = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($akun && password_verify($password, $akun["password"])) {

            session_regenerate_id(true);

            $_SESSION["id_akun"] = $akun["id_akun"];
            $_SESSION["username"] = $akun["username"];
            $_SESSION["role"] = $akun["role"];

            if ($akun["role"] === "Admin") {
                header("Location: admin/dashboard.php");
                exit;
            }

            if ($akun["role"] === "Mahasiswa") {
                header("Location: mahasiswa/dashboard.php");
                exit;
            }

            $error = "Role akun tidak valid.";

        } else {

            $error = "Username/email atau password salah.";

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
    <title>Login - Merchandise Kampus</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/auth.js" defer></script>
</head>
<body class="page-login">

<?php require __DIR__ . "/includes/navbar.php"; ?>

<main class="auth">
    <section class="card">
        <img class="card__logo" src="assets/img/logo-jti.png" alt="Logo JTI">

        <form class="form" method="POST" data-auth>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

            <?php if ($success !== ""): ?>
                <div class="alert alert--success" role="status"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php
            // name="login" = nama field yang dibaca login.php (username ATAU email)
            field("Username", "login", "ENTER USERNAME", ["icon" => "user", "value" => $login ?? "", "attrs" => 'autocomplete="username"']);
            field("Password", "password", "ENTER PASSWORD", ["type" => "password", "icon" => "lock", "attrs" => 'autocomplete="current-password"']);
            ?>

            <button class="btn" type="submit">
                <span>Submit</span><?= icon("arrow") ?><span class="spinner"></span>
            </button>
        </form>

        <p class="switch">Tidak punya akun? <a href="register.php">Register</a></p>
    </section>
</main>

</body>
</html>
