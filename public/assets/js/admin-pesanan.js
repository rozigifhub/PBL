/**
 * Kelola Pesanan admin (admin/pesanan.php) — interaksi kecil, tanpa mengubah data.
 * Pencarian, dropdown filter status, dan "Pilih Semua" ada di assets/js/pesanan-tools.js (dimuat lebih dulu).
 * Semua fitur bersifat tambahan: tanpa JS, tautan nomor pesanan tetap berfungsi.
 *
 * 1. Baris tabel: seluruh baris bisa diklik untuk membuka detail pesanan (Ctrl/Cmd + klik = tab baru).
 *    Klik pada tautan, tombol, atau checkbox di dalam baris tetap bekerja seperti biasa, dan memilih/menyalin teks tidak memicu pindah halaman.
 * 2. Menu akun di navbar menutup saat klik di luar menu atau menekan Esc
 *    (sama dengan perilaku di admin-dashboard.js).
 */
(() => {
    "use strict";

    // ---------- 1. Baris bisa diklik ----------
    const tabel = document.querySelector("[data-pt-table]");
    if (tabel) {
        tabel.classList.add("is-clickable");

        tabel.addEventListener("click", (e) => {
            const baris = e.target.closest("tr[data-href]");
            if (!baris || e.target.closest("a, button, input, label, .pt-td-check")) return;   // kontrol asli diurus browser
            if (String(window.getSelection()).length > 0) return;       // sedang menyeleksi teks

            if (e.ctrlKey || e.metaKey) {
                window.open(baris.dataset.href, "_blank", "noopener");
            } else {
                window.location.href = baris.dataset.href;
            }
        });
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
