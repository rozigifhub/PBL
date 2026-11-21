/**
 * Laporan admin (admin/laporan.php) — interaksi kecil, tanpa mengubah data/form.
 *
 * 1. Kolom tanggal "Dari" & "Sampai" tetap <input type="date" name="dari|sampai"> (dikirim lewat GET seperti biasa).
 *    JS hanya menampilkan nilainya dengan format desain ("01 Nov 2026") di atas input, dan membuka kalender
 *    saat kolom diklik. Tanpa JS, input date bawaan browser tampil apa adanya.
 * 2. Menu akun di navbar menutup saat klik di luar menu atau menekan Esc
 *    (sama dengan perilaku di admin-withdraw.js dan admin-pesanan.js).
 */
(() => {
    "use strict";

    /* ---------- 1. Format tampilan tanggal ---------- */
    let fmt = null;
    try {
        fmt = new Intl.DateTimeFormat("id-ID", { day: "2-digit", month: "short", year: "numeric" });
    } catch (err) { /* browser lama: biarkan tampilan bawaan */ }

    const tampil = (nilai) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(nilai);
        if (!m || !fmt) return "";
        return fmt.format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
    };

    if (fmt) {
        document.querySelectorAll("[data-lp-date]").forEach((wrap) => {
            const input = wrap.querySelector('input[type="date"]');
            const teks  = wrap.querySelector("[data-lp-date-text]");
            if (!input || !teks) return;

            const sinkron = () => { teks.textContent = tampil(input.value); };
            sinkron();
            wrap.classList.add("is-enhanced");

            input.addEventListener("input", sinkron);
            input.addEventListener("change", sinkron);

            // Klik di mana saja pada kolom -> buka kalender (showPicker belum ada di browser lama; di sana klik bawaan tetap jalan)
            input.addEventListener("click", () => {
                if (typeof input.showPicker === "function") {
                    try { input.showPicker(); } catch (err) { /* sudah terbuka / tidak diizinkan */ }
                }
            });
        });
    }

    /* ---------- 2. Menu akun navbar ---------- */
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
