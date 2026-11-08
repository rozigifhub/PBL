/**
 * Detail Pesanan admin (admin/pesanan-detail.php) — interaksi kecil, tanpa mengubah data.
 * Semua fitur bersifat tambahan: tanpa JS, form Ubah Status & Pengiriman tetap bisa dikirim seperti biasa.
 *
 * 1. Kolom "Tanggal Pengiriman" yang masih kosong tampil abu-abu (seperti placeholder di desain);
 *    begitu terisi, warnanya kembali normal. Nilai & atribut name input tidak disentuh.
 * 2. Tombol submit dinonaktifkan sebentar setelah form dikirim, supaya klik ganda
 *    tidak mengirim perubahan status dua kali.
 * 3. Menu akun di navbar menutup saat klik di luar menu atau menekan Esc
 *    (sama dengan perilaku di admin-pesanan.js dan admin-dashboard.js).
 */
(() => {
    "use strict";

    // ---------- 1. Tanggal kosong = abu-abu ----------
    document.querySelectorAll('input[type="datetime-local"].pd-field').forEach((input) => {
        const tandai = () => input.classList.toggle("is-empty", input.value === "");
        tandai();
        input.addEventListener("input", tandai);
        input.addEventListener("change", tandai);
    });

    // ---------- 2. Cegah klik ganda pada tombol submit ----------
    document.querySelectorAll("form.pd-form").forEach((form) => {
        form.addEventListener("submit", () => {
            // Ditunda satu tick: tombol baru dinonaktifkan setelah browser menyusun data form
            // (tombol yang disabled sebelum itu bisa ikut hilang dari data yang dikirim).
            setTimeout(() => {
                form.querySelectorAll('button[type="submit"]').forEach((tombol) => {
                    tombol.disabled = true;
                });
            }, 0);
        });
    });

    // Halaman dipulihkan dari cache "kembali" browser: aktifkan lagi tombolnya
    window.addEventListener("pageshow", (e) => {
        if (!e.persisted) return;
        document.querySelectorAll("form.pd-form button[type=\"submit\"]").forEach((tombol) => {
            tombol.disabled = false;
        });
    });

    // ---------- 3. Menu akun ----------
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
