<?php

require_once "../config/database.php";

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

        <p>Belum ada kategori merchandise.</p>

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
        Belum punya akun?
        <a href="register.php">Register</a>
    </p>


</body>
</html>