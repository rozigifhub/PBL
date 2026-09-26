<?php

session_start();

if (!isset($_SESSION["id_akun"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "Mahasiswa") {
    header("Location: ../login.php");
    exit;
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Mahasiswa</title>
</head>
<body>

    <h1>Dashboard Mahasiswa</h1>

    <p>
        Selamat datang,
        <?= htmlspecialchars($_SESSION["username"]) ?>
    </p>

    <p>
        Role:
        <?= htmlspecialchars($_SESSION["role"]) ?>
    </p>

    <a href="../logout.php">Logout</a>

</body>
</html>