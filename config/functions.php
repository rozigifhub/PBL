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

    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "httponly" => true,   // cookie session tidak terbaca JavaScript
        "samesite" => "Lax",  // proteksi CSRF lintas situs
        // "secure" => true,   // nyalakan setelah aplikasi memakai HTTPS
    ]);

    session_start();
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
