/**
 * Kelola Profil mahasiswa (mahasiswa/profil.php) — interaksi kecil, tanpa mengubah data form.
 *
 * Ikon mata di kolom password: klik untuk menampilkan / menyembunyikan teks.
 * Hanya mengganti atribut type input (password <-> text); name, value, dan pengiriman form tidak berubah.
 * Tanpa JS, kolom password tetap berfungsi normal (teks tersembunyi).
 */
(() => {
    "use strict";

    const tombolMata = Array.from(document.querySelectorAll("[data-pf-eye]"));
    if (!tombolMata.length) return;

    const inputDari = (tombol) => {
        const bungkus = tombol.closest(".pf-pass");
        return bungkus ? bungkus.querySelector("input") : null;
    };

    const atur = (tombol, input, tampil) => {
        input.type = tampil ? "text" : "password";
        tombol.setAttribute("aria-pressed", tampil ? "true" : "false");
        tombol.setAttribute("aria-label", tampil ? "Sembunyikan password" : "Tampilkan password");
    };

    tombolMata.forEach((tombol) => {
        const input = inputDari(tombol);
        if (!input) return;

        tombol.addEventListener("click", () => {
            const awal = input.selectionStart;
            const akhir = input.selectionEnd;

            atur(tombol, input, input.type === "password");

            // Kembalikan fokus & posisi kursor supaya pengguna bisa langsung lanjut mengetik
            input.focus({ preventScroll: true });
            try { input.setSelectionRange(awal, akhir); } catch (e) { /* abaikan */ }
        });
    });

    // Tombol "kembali" di browser (bfcache): jangan biarkan password tetap terlihat
    window.addEventListener("pageshow", (e) => {
        if (!e.persisted) return;
        tombolMata.forEach((tombol) => {
            const input = inputDari(tombol);
            if (input) atur(tombol, input, false);
        });
    });
})();
