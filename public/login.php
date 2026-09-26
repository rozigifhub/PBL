<?php

session_start();

require_once "../config/database.php";

$error = "";
$success = "";

if (isset($_GET["register"]) && $_GET["register"] === "success") {
    $success = "Registrasi berhasil. Silakan login.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $login = trim($_POST["login"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($login === "" || $password === "") {

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
               OR email = :login
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

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login - Merchandise Kampus</title>
</head>
<body>

    <h1>Login</h1>

    <?php if ($success !== ""): ?>
        <p><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">

        <label>Username atau Email</label><br>
        <input type="text" name="login" required>

        <br><br>

        <label>Password</label><br>
        <input type="password" name="password" required>

        <br><br>

        <button type="submit">Login</button>

    </form>

    <p>
        Belum punya akun?
        <a href="register.php">Register</a>
    </p>

</body>
</html>