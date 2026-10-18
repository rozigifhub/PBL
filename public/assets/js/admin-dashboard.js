/**
 * Dashboard admin (admin/dashboard.php) — interaksi kecil, tanpa mengubah data.
 *
 * 1. Angka pada kartu statistik "berhitung naik" dari 0 ke nilai sebenarnya saat halaman dibuka.
 *    Nilai akhir selalu sudah tertulis di HTML (atribut data-count), jadi tanpa JS
 *    atau saat pengguna memilih "kurangi animasi", angka langsung tampil apa adanya.
 * 2. Menu akun di navbar (ikon orang) menutup saat klik di luar menu atau menekan Esc.
 */
(() => {
    "use strict";

    const kurangiGerak = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // ---------- 1. Hitung naik ----------
    const formatAngka = new Intl.NumberFormat("id-ID");
    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

    const hitungNaik = (el, urutan) => {
        const target = Number(el.dataset.count);
        if (!Number.isFinite(target) || target <= 0) return;

        const awalan = el.dataset.prefix || "";
        const durasi = 900;                 // ms
        const tunda  = 150 + urutan * 90;   // ikut urutan kemunculan kartu (lihat animation-delay di CSS)
        const tulis  = (n) => { el.textContent = awalan + formatAngka.format(Math.round(n)); };

        tulis(0);
        let mulai = null;
        const langkah = (waktu) => {
            if (mulai === null) mulai = waktu;
            const t = Math.min((waktu - mulai) / durasi, 1);
            tulis(target * easeOutCubic(t));
            if (t < 1) requestAnimationFrame(langkah);
        };
        setTimeout(() => requestAnimationFrame(langkah), tunda);
    };

    if (!kurangiGerak) {
        document.querySelectorAll("[data-count]").forEach(hitungNaik);
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
