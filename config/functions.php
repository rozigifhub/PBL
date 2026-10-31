<?php

/**
 * Helper backend aplikasi (session, CSRF, guard auth).
 * Murni logika — tidak berisi UI.
 *
 * Pemakaian di halaman:
 *   require_once "../config/functions.php";
 *   app_session_start();
 *   require_once "../config/database.php";
 *   require_admin();          // untuk halaman admin
 */

// ---------- Session ----------

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Tolak ID session yang tidak pernah dibuat server (cegah session fixation);
    // ID hanya boleh lewat cookie, tidak lewat URL.
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");

    // Nginx meneruskan HTTPS=on ke PHP-FPM; di HTTP biasa (mis. pengembangan lokal) flag Secure
    // dimatikan agar cookie tetap terkirim.
    $https = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";

    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "secure"   => $https, // cookie hanya dikirim lewat HTTPS
        "httponly" => true,   // cookie session tidak terbaca JavaScript
        "samesite" => "Lax",  // proteksi CSRF lintas situs
    ]);

    session_start();
}

/**
 * Mulai session HANYA bila pengunjung sudah punya cookie session.
 * Untuk halaman publik (katalog, detail produk): tamu tanpa cookie tidak
 * membuat file session dan tidak menerima Set-Cookie, sehingga respons
 * halaman tamu bisa di-cache Nginx. Return: true bila session dimulai.
 */
function app_session_opsional(): bool
{
    if (!isset($_COOKIE[session_name()])) {
        return false;
    }
    app_session_start();
    return true;
}

// ---------- CSRF ----------

function csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function csrf_validate(): bool
{
    $token = $_POST["csrf_token"] ?? "";

    return isset($_SESSION["csrf_token"])
        && is_string($token)
        && hash_equals($_SESSION["csrf_token"], $token);
}

// ---------- Guard auth ----------

function require_login(string $loginUrl = "../login.php"): void
{
    if (empty($_SESSION["id_akun"])) {
        header("Location: " . $loginUrl);
        exit;
    }
}

function require_admin(string $loginUrl = "../login.php"): void
{
    require_login($loginUrl);

    if (($_SESSION["role"] ?? "") !== "Admin") {
        header("Location: " . $loginUrl);
        exit;
    }
}

function require_mahasiswa(string $loginUrl = "../login.php"): void
{
    require_login($loginUrl);

    if (($_SESSION["role"] ?? "") !== "Mahasiswa") {
        header("Location: " . $loginUrl);
        exit;
    }
}

// ---------- Optimasi database ----------

/** Segarkan materialized view rekap penjualan (gagal hanya dicatat di log). */
function refresh_mv_rekap(PDO $pdo): void
{
    try {
        $pdo->exec("REFRESH MATERIALIZED VIEW CONCURRENTLY mv_rekap_penjualan_produk");
    } catch (PDOException $e) {
        error_log("Gagal refresh mv_rekap_penjualan_produk: " . $e->getMessage());
    }
}

// ---------- Util ----------

/** Escape output HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function redirect(string $url): void
{
    header("Location: " . $url);
    exit;
}

/** Simpan pesan sekali-tampil (Post-Redirect-Get). */
function set_flash(string $type, string $msg): void
{
    $_SESSION["flash"] = ["type" => $type, "msg" => $msg];
}

/** Ambil + hapus pesan flash. Return: ["type"=>..., "msg"=>...] atau null. */
function take_flash(): ?array
{
    if (isset($_SESSION["flash"])) {
        $flash = $_SESSION["flash"];
        unset($_SESSION["flash"]);
        return $flash;
    }
    return null;
}
