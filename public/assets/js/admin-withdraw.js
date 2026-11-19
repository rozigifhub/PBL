/**
 * Withdraw Dompet admin (admin/withdraw.php) — interaksi kecil, tanpa mengubah data.
 * Dropdown filter status ("Semua" / "Selesai" / "Ditolak") ada di assets/js/pesanan-tools.js (dimuat lebih dulu,
 * dipakai bersama dengan Kelola Pesanan). Opsi dropdown berupa tautan ?status=..., jadi filter tetap dijalankan
 * query backend dan tetap bekerja walau JS mati.
 *
 * 1. Menu akun di navbar menutup saat klik di luar menu atau menekan Esc
 *    (sama dengan perilaku di admin-dashboard.js dan admin-pesanan.js).
 */
(() => {
    "use strict";

    const akun = document.querySelector(".navbar__account");
    if (!akun) return;

    document.addEventListener("click", (e) => {
        if (akun.open && !akun.contains(e.target)) akun.open = false;
    });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && akun.open) {
            akun.open = false;
            akun.querySelector("summary").focus();
        }
    });
})();
