/**
 * Kelola Pesanan admin (admin/pesanan.php) — interaksi kecil, tanpa mengubah data.
 * Semua fitur bersifat tambahan: tanpa JS, tab filter dan tautan nomor pesanan tetap berfungsi.
 *
 * 1. Tab filter: tab yang aktif digeser ke tengah bilah (berguna di layar kecil, saat bilah bisa digeser).
 * 2. Baris tabel: seluruh baris bisa diklik untuk membuka detail pesanan (Ctrl/Cmd + klik = tab baru).
 *    Klik pada tautan di dalam baris tetap bekerja seperti biasa, dan memilih/menyalin teks tidak memicu pindah halaman.
 * 3. Menu akun di navbar menutup saat klik di luar menu atau menekan Esc
 *    (sama dengan perilaku di admin-dashboard.js).
 */
(() => {
    "use strict";

    // ---------- 1. Tab aktif ke tengah ----------
    const bilahTab = document.querySelector(".po-tabs");
    const tabAktif = bilahTab ? bilahTab.querySelector(".po-tab.is-active") : null;
    if (bilahTab && tabAktif && bilahTab.scrollWidth > bilahTab.clientWidth) {
        // Geser bilahnya saja (bukan scrollIntoView) supaya halaman tidak ikut tergulir
        bilahTab.scrollLeft = tabAktif.offsetLeft - (bilahTab.clientWidth - tabAktif.offsetWidth) / 2;
    }

    // ---------- 2. Baris bisa diklik ----------
    const tabel = document.querySelector(".po-table");
    if (tabel) {
        tabel.classList.add("is-clickable");

        tabel.addEventListener("click", (e) => {
            const baris = e.target.closest("tr[data-href]");
            if (!baris || e.target.closest("a, button")) return;       // tautan asli diurus browser
            if (String(window.getSelection()).length > 0) return;       // sedang menyeleksi teks

            if (e.ctrlKey || e.metaKey) {
                window.open(baris.dataset.href, "_blank", "noopener");
            } else {
                window.location.href = baris.dataset.href;
            }
        });
    }

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
