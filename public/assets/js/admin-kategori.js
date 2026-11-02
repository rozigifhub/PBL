/**
 * Kelola Kategori admin (admin/kategori.php) — interaksi kecil, tanpa mengubah data.
 * Semua fitur bersifat tambahan: tanpa JS, form tambah/ubah, tombol Edit, dan tombol Hapus
 * (beserta konfirmasi di atribut onsubmit) tetap berfungsi seperti dari tim backend.
 *
 * 1. Mode ubah (?edit=ID): kolom nama langsung terfokus dan teksnya terpilih,
 *    jadi admin bisa langsung mengetik nama baru.
 * 2. Menu akun di navbar menutup saat klik di luar menu atau menekan Esc
 *    (sama dengan perilaku di admin-dashboard.js dan admin-pesanan.js).
 */
(() => {
    "use strict";

    // ---------- 1. Mode ubah: fokus + pilih teks ----------
    const form = document.querySelector(".kt-form[data-mode=\"ubah\"]");
    const input = form ? form.querySelector(".kt-input") : null;
    if (input) {
        input.focus({ preventScroll: true });
        input.select();
    }

    // ---------- 2. Menu akun ----------
    const akun = document.querySelector(".navbar__account");
    if (akun) {
        document.addEventListener("click", (e) => {
            if (akun.open && !akun.contains(e.target)) akun.open = false;
        });
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && akun.open) {
                akun.open = false;
                akun.querySelector("summary").focus();
            }
        });
    }
})();
