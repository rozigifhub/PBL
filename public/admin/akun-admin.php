<?php

/**
 * Admin — Kelola Akun Admin: tambah admin baru, reset password admin, hapus admin.
 * Semua akun Admin setara (tidak ada tingkat super admin).
 *
 * Pengaman:
 *   - Tidak bisa menghapus akun sendiri (mencegah terkunci dari sistem).
 *   - Tidak bisa menghapus admin terakhir (minimal 1 admin harus tetap ada).
 *   - Penghapusan ditolak database (FK RESTRICT) bila admin itu pernah memproses
 *     pesanan/pengiriman/withdraw; pesan error diterjemahkan jadi ramah.
 */

require_once __DIR__ . "/../../config/functions.php";
app_session_start();
require_once __DIR__ . "/../../config/database.php";
require_admin();

$error     = "";
$idSendiri = (int)($_SESSION["id_akun"] ?? 0);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate()) {
        $error = "Sesi tidak valid. Silakan coba lagi.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "tambah") {

                $username = trim($_POST["username"] ?? "");
                $email    = strtolower(trim($_POST["email"] ?? ""));
                $password = (string)($_POST["password"] ?? "");
                $konfirm  = (string)($_POST["password_konfirmasi"] ?? "");

                if ($username === "" || $email === "" || $password === "") {
                    $error = "Semua kolom wajib diisi.";
                } elseif (!preg_match("/^[A-Za-z0-9_.]{3,50}$/", $username)) {
                    $error = "Username 3-50 karakter, hanya huruf, angka, titik, dan garis bawah.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Format email tidak valid.";
                } elseif (strlen($password) < 8) {
                    $error = "Password minimal 8 karakter.";
                } elseif ($password !== $konfirm) {
                    $error = "Konfirmasi password tidak sama.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO akun_login (username, email, password, role)
                        VALUES (:username, :email, :hash, 'Admin')
                    ");
                    $stmt->execute([
                        ":username" => $username,
                        ":email"    => $email,
                        ":hash"     => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    catat_log_admin($pdo, "Tambah", "Akun Admin",
                        (int)$pdo->lastInsertId("akun_login_id_akun_seq"), "username: {$username}, email: {$email}");
                    set_flash("sukses", "Admin \"{$username}\" ditambahkan.");
                    redirect("akun-admin.php");
                }

            } elseif ($action === "reset_password") {

                $id      = (int)($_POST["id_akun"] ?? 0);
                $baru    = (string)($_POST["password_baru"] ?? "");
                $konfirm = (string)($_POST["password_konfirmasi"] ?? "");

                $stmt = $pdo->prepare("SELECT username FROM akun_login WHERE id_akun = :id AND role = 'Admin'");
                $stmt->execute([":id" => $id]);
                $username = $stmt->fetchColumn();

                if ($username === false) {
                    $error = "Admin tidak ditemukan.";
                } elseif (strlen($baru) < 8) {
                    $error = "Password baru minimal 8 karakter.";
                } elseif ($baru !== $konfirm) {
                    $error = "Konfirmasi password tidak sama.";
                } else {
                    $stmt = $pdo->prepare("UPDATE akun_login SET password = :hash WHERE id_akun = :id");
                    $stmt->execute([":hash" => password_hash($baru, PASSWORD_DEFAULT), ":id" => $id]);
                    catat_log_admin($pdo, "Reset Password", "Akun Admin", $id, "username: {$username}");
                    if ($id === $idSendiri) {
                        session_regenerate_id(true);
                    }
                    set_flash("sukses", "Password admin \"{$username}\" berhasil direset.");
                    redirect("akun-admin.php");
                }

            } elseif ($action === "hapus") {

                $id = (int)($_POST["id_akun"] ?? 0);

                if ($id === $idSendiri) {
                    $error = "Tidak bisa menghapus akun sendiri.";
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM akun_login WHERE role = 'Admin'");
                    $jumlahAdmin = (int)$stmt->fetchColumn();

                    if ($jumlahAdmin <= 1) {
                        $error = "Tidak bisa menghapus admin terakhir.";
                    } else {
                        $stmt = $pdo->prepare("SELECT username FROM akun_login WHERE id_akun = :id AND role = 'Admin'");
                        $stmt->execute([":id" => $id]);
                        $username = $stmt->fetchColumn();

                        if ($username === false) {
                            $error = "Admin tidak ditemukan.";
                        } else {
                            try {
                                $stmt = $pdo->prepare("DELETE FROM akun_login WHERE id_akun = :id AND role = 'Admin'");
                                $stmt->execute([":id" => $id]);
                                catat_log_admin($pdo, "Hapus", "Akun Admin", $id, "username: {$username}");
                                set_flash("sukses", "Admin \"{$username}\" dihapus.");
                                redirect("akun-admin.php");
                            } catch (PDOException $e) {
                                $error = "Admin \"{$username}\" tidak bisa dihapus karena pernah memproses pesanan/pengiriman/withdraw.";
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Kelola akun admin gagal: " . $e->getMessage());
            $error = str_contains($e->getMessage(), "duplicate key")
                ? "Username atau email sudah dipakai."
                : "Terjadi kesalahan database. Coba lagi.";
        }
    }
}

$daftarAdmin = $pdo->query("
    SELECT id_akun, username, email, created_at
    FROM akun_login
    WHERE role = 'Admin'
    ORDER BY id_akun
")->fetchAll();

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Admin - Admin</title>
    <link rel="preload" href="/assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . "/../assets/css/style.css") ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-dashboard.css") ?>">
    <link rel="stylesheet" href="/assets/css/admin-kategori.css?v=<?= @filemtime(__DIR__ . "/../assets/css/admin-kategori.css") ?>">
</head>
<body class="page-dashboard">
<?php
$adminActive     = "akun";
$adminNavRingkas = true;
require __DIR__ . "/../includes/navbar-admin.php";
?>

<main class="dash dash-kategori">
    <div class="kt">

        <header class="kt-head">
            <nav class="kt-crumb" aria-label="Breadcrumb">
                <span><a href="/admin/dashboard.php">Dashboard</a> / <a href="/admin/akun-admin.php" aria-current="page">Kelola Admin</a></span>
            </nav>
            <div class="kt-title">
                <div>
                    <h1>Kelola Admin</h1>
                    <p>Semua akun admin setara: tidak ada tingkat super admin</p>
                </div>
            </div>
        </header>

        <section class="kt-card" aria-label="Daftar admin">

            <?php if ($flash): ?>
                <div class="alert <?= ($flash["type"] ?? "") === "sukses" ? "alert--success" : "alert--error" ?>" role="<?= ($flash["type"] ?? "") === "sukses" ? "status" : "alert" ?>">
                    <?= e($flash["msg"]) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- Form tambah admin baru -->
            <form class="kt-form" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="tambah">

                <label class="kt-form__label" for="username">Tambah Admin Baru</label>

                <div class="kt-form__row">
                    <input class="kt-input" id="username" type="text" name="username" required maxlength="50"
                           placeholder="Username" autocomplete="off"
                           value="<?= e($_POST["username"] ?? "") ?>">
                    <input class="kt-input" id="email" type="email" name="email" required maxlength="100"
                           placeholder="Email" autocomplete="off"
                           value="<?= e($_POST["email"] ?? "") ?>">
                    <input class="kt-input" type="password" name="password" required minlength="8"
                           placeholder="Password (minimal 8 karakter)" autocomplete="new-password">
                    <input class="kt-input" type="password" name="password_konfirmasi" required minlength="8"
                           placeholder="Konfirmasi password" autocomplete="new-password">

                    <button class="kt-add" type="submit">
                        <span class="kt-add__plus" aria-hidden="true">+</span><span>Tambah Admin</span>
                    </button>
                </div>
            </form>

            <?php if (!$daftarAdmin): ?>

                <div class="dash-empty">
                    <p>Belum ada admin.</p>
                </div>

            <?php else: ?>

                <div class="kt-table-wrap">
                    <table class="kt-table">
                        <caption class="kt-sr">Daftar admin</caption>
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Username</th>
                                <th scope="col">Email</th>
                                <th scope="col">Dibuat</th>
                                <th scope="col" class="kt-col-aksi">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daftarAdmin as $i => $a): ?>
                                <?php
                                $idA      = (int)$a["id_akun"];
                                $namaA    = (string)$a["username"];
                                $diriSend = $idA === $idSendiri;
                                $konfirm  = json_encode("Hapus admin \"{$namaA}\"? Tindakan ini tidak bisa dibatalkan.", JSON_UNESCAPED_UNICODE);
                                ?>
                                <tr style="--i:<?= (int)$i ?>">
                                    <td data-label="ID" class="is-num"><?= $idA ?></td>
                                    <td data-label="Username" class="kt-name"><?= e($namaA) ?><?= $diriSend ? " <small>(kamu)</small>" : "" ?></td>
                                    <td data-label="Email"><?= e((string)$a["email"]) ?></td>
                                    <td data-label="Dibuat"><?= e(substr((string)$a["created_at"], 0, 10)) ?></td>
                                    <td data-label="Aksi" class="kt-aksi">
                                        <div class="kt-actions">
                                            <details>
                                                <summary class="kt-btn kt-btn--edit">Reset Password</summary>
                                                <form method="POST" style="margin-top:8px">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="reset_password">
                                                    <input type="hidden" name="id_akun" value="<?= $idA ?>">
                                                    <input class="kt-input" type="password" name="password_baru" required minlength="8"
                                                           placeholder="Password baru" autocomplete="new-password">
                                                    <input class="kt-input" type="password" name="password_konfirmasi" required minlength="8"
                                                           placeholder="Konfirmasi" autocomplete="new-password">
                                                    <button class="kt-btn kt-btn--edit" type="submit">Simpan Password Baru</button>
                                                </form>
                                            </details>

                                            <?php if (!$diriSend): ?>
                                                <form class="kt-del" method="POST" onsubmit="return confirm(<?= e($konfirm) ?>)">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="hapus">
                                                    <input type="hidden" name="id_akun" value="<?= $idA ?>">
                                                    <button class="kt-btn kt-btn--hapus" type="submit" aria-label="Hapus admin <?= e($namaA) ?>">Hapus</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </section>
    </div>
</main>

</body>
</html>
