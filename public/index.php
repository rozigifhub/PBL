<?php

session_start();

require_once __DIR__ . "/../config/database.php";

$query = $pdo->query("
    SELECT 
        id_kategori,
        nama_kategori
    FROM kategori_merchandise
    ORDER BY id_kategori
");

$kategori = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Merchandise Kampus</title>
</head>
<body>

    <h1>Merchandise Kampus</h1>

    <h2>Kategori</h2>

    <?php if (empty($kategori)): ?>

        <p>Belum ada kategori merchandise untuk saat ini.</p>

    <?php else: ?>

        <ul>
            <?php foreach ($kategori as $item): ?>
                <li>
                    <?= htmlspecialchars($item["nama_kategori"]) ?>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>

    <p>
        login dulu!
        <a href="login.php">Login</a>
    </p>


</body>
</html>