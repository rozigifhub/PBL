/**
 * Riwayat Pesanan mahasiswa (mahasiswa/riwayat.php) — interaksi kecil, tanpa mengubah data.
 * Pencarian, dropdown filter status, dan "Pilih Semua" ada di assets/js/pesanan-tools.js (dimuat lebih dulu).
 * Semua fitur bersifat tambahan: tanpa JS, tabel tetap menampilkan seluruh pesanan dan
 * tautan nomor pesanan / tombol Detail tetap berfungsi.
 *
 * Baris tabel: seluruh baris bisa diklik untuk membuka detail pesanan (Ctrl/Cmd + klik = tab baru).
 * Klik pada tautan, tombol, atau checkbox di dalam baris tetap bekerja seperti biasa, dan memilih/menyalin teks tidak memicu pindah halaman.
 */
(() => {
    "use strict";

    const tabel = document.querySelector("[data-pt-table]");
    if (!tabel) return;                                   // tidak ada pesanan: tidak ada yang perlu dijalankan

    tabel.classList.add("is-clickable");
    tabel.addEventListener("click", (e) => {
        const tr = e.target.closest("tr[data-href]");
        if (!tr || e.target.closest("a, button, input, label, .pt-td-check")) return;   // kontrol asli diurus browser
        if (String(window.getSelection()).length > 0) return;                            // sedang menyeleksi teks

        if (e.ctrlKey || e.metaKey) {
            window.open(tr.dataset.href, "_blank", "noopener");
        } else {
            window.location.href = tr.dataset.href;
        }
    });
})();
