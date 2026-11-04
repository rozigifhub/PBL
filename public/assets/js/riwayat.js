/**
 * Riwayat Pesanan mahasiswa (mahasiswa/riwayat.php) — interaksi kecil, tanpa mengubah data.
 * Semua fitur bersifat tambahan: tanpa JS, tabel tetap menampilkan seluruh pesanan dan
 * tautan nomor pesanan / tombol Detail tetap berfungsi.
 *
 * 1. Tab status: shape oranye (.rw-tabs__slider) bergeser mulus ke tab yang dipilih
 *    (CSS: transform translateX + transition .3s ease-in-out; JS hanya menghitung posisi & lebarnya).
 * 2. Filter: baris tabel disaring di browser lewat atribut data-status (diisi PHP dari status_pesanan),
 *    jadi tidak ada query tambahan. Pilihan tab disimpan di hash URL (#lunas, dst.) supaya
 *    tetap terpilih saat kembali dari halaman detail.
 * 3. Baris tabel: seluruh baris bisa diklik untuk membuka detail pesanan (Ctrl/Cmd + klik = tab baru).
 *    Klik pada tautan di dalam baris tetap bekerja seperti biasa, dan memilih/menyalin teks tidak memicu pindah halaman.
 */
(() => {
    "use strict";

    const bilah = document.querySelector("[data-rw-tabs]");
    const tabel = document.querySelector("[data-rw-table]");
    if (!bilah || !tabel) return;                       // tidak ada pesanan: tidak ada yang perlu dijalankan

    const slider  = bilah.querySelector(".rw-tabs__slider");
    const tabs    = Array.from(bilah.querySelectorAll(".rw-tab"));
    const baris   = Array.from(tabel.querySelectorAll("tbody tr"));
    const bungkus = document.querySelector("[data-rw-wrap]");
    const kosong  = document.querySelector("[data-rw-empty]");
    const teksKosong = document.querySelector("[data-rw-empty-text]");
    const info    = document.querySelector("[data-rw-info]");
    const kunciAwal = "semua";
    let aktif = null;

    // ---------- 1. Shape oranye ----------
    const geserSlider = (tab) => {
        slider.style.width = tab.offsetWidth + "px";
        slider.style.transform = "translateX(" + tab.offsetLeft + "px)";
    };

    // Tempatkan ulang tanpa animasi (dipakai saat awal, ganti ukuran layar, dan setelah font termuat)
    const tempatkanLangsung = () => {
        if (!aktif) return;
        bilah.classList.add("is-resizing");
        geserSlider(aktif);
        requestAnimationFrame(() => requestAnimationFrame(() => bilah.classList.remove("is-resizing")));
    };

    // ---------- 2. Filter ----------
    const terapkanFilter = (tab) => {
        const daftar = tab.dataset.filter ? tab.dataset.filter.split("|") : null;   // null = semua
        let tampil = 0;

        baris.forEach((tr) => {
            const cocok = !daftar || daftar.includes(tr.dataset.status);
            tr.hidden = !cocok;
            tr.classList.remove("is-enter");
            if (cocok) {
                tr.style.setProperty("--i", tampil);
                tampil++;
            }
        });

        void tabel.offsetWidth;                          // paksa reflow agar animasi muncul bisa diulang
        baris.forEach((tr) => { if (!tr.hidden) tr.classList.add("is-enter"); });

        const label = tab.textContent.trim();
        if (bungkus) bungkus.hidden = tampil === 0;
        if (kosong) kosong.hidden = tampil !== 0;
        if (teksKosong) teksKosong.textContent = "Tidak ada pesanan dengan status " + label + ".";
        if (info) info.textContent = tampil === 0
            ? "Tidak ada pesanan dengan status " + label + "."
            : "Menampilkan " + tampil + " pesanan" + (tab.dataset.key === kunciAwal ? "." : " dengan status " + label + ".");
    };

    const pilihTab = (tab, { fokus = false, animasi = true } = {}) => {
        aktif = tab;
        tabs.forEach((t) => {
            const dipilih = t === tab;
            t.classList.toggle("is-active", dipilih);
            t.setAttribute("aria-selected", dipilih ? "true" : "false");
            t.tabIndex = dipilih ? 0 : -1;
        });

        if (animasi) {
            geserSlider(tab);
        } else {
            tempatkanLangsung();
        }
        terapkanFilter(tab);
        if (fokus) tab.focus();

        // Layar kecil: bilah tab bisa digeser, jadi bawa tab aktif ke tengah bilah
        if (bilah.scrollWidth > bilah.clientWidth) {
            bilah.scrollTo({
                left: tab.offsetLeft - (bilah.clientWidth - tab.offsetWidth) / 2,
                behavior: animasi ? "smooth" : "auto",
            });
        }

        // Simpan pilihan di hash (tanpa menambah riwayat browser)
        const hash = tab.dataset.key === kunciAwal ? "" : "#" + tab.dataset.key;
        history.replaceState(null, "", location.pathname + location.search + hash);
    };

    // Awal: ikuti hash URL bila cocok dengan salah satu tab, kalau tidak "Semua"
    const kunciHash = location.hash.replace(/^#/, "");
    const tabAwal = tabs.find((t) => t.dataset.key === kunciHash) || tabs.find((t) => t.dataset.key === kunciAwal) || tabs[0];
    pilihTab(tabAwal, { animasi: false });
    requestAnimationFrame(() => bilah.classList.add("is-ready"));   // aktifkan transisi setelah posisi awal terpasang

    tabs.forEach((tab) => tab.addEventListener("click", () => { if (tab !== aktif) pilihTab(tab); }));

    // Navigasi keyboard pada bilah tab: panah kiri/kanan, Home, End
    bilah.addEventListener("keydown", (e) => {
        const i = tabs.indexOf(document.activeElement);
        if (i === -1) return;
        let tujuan = null;
        if (e.key === "ArrowRight") tujuan = tabs[(i + 1) % tabs.length];
        else if (e.key === "ArrowLeft") tujuan = tabs[(i - 1 + tabs.length) % tabs.length];
        else if (e.key === "Home") tujuan = tabs[0];
        else if (e.key === "End") tujuan = tabs[tabs.length - 1];
        if (!tujuan) return;
        e.preventDefault();
        pilihTab(tujuan, { fokus: true });
    });

    // Tombol "Tampilkan semua pesanan" pada pesan kosong
    document.querySelectorAll("[data-rw-reset]").forEach((tombol) => {
        tombol.addEventListener("click", () => pilihTab(tabs.find((t) => t.dataset.key === kunciAwal) || tabs[0], { fokus: true }));
    });

    // Ukuran layar / font berubah: lebar tab ikut berubah, jadi shape ditempatkan ulang
    if ("ResizeObserver" in window) {
        new ResizeObserver(tempatkanLangsung).observe(bilah);
    } else {
        window.addEventListener("resize", tempatkanLangsung);
    }
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(tempatkanLangsung);

    // ---------- 3. Baris bisa diklik ----------
    tabel.classList.add("is-clickable");
    tabel.addEventListener("click", (e) => {
        const tr = e.target.closest("tr[data-href]");
        if (!tr || e.target.closest("a, button")) return;       // tautan asli diurus browser
        if (String(window.getSelection()).length > 0) return;    // sedang menyeleksi teks

        if (e.ctrlKey || e.metaKey) {
            window.open(tr.dataset.href, "_blank", "noopener");
        } else {
            window.location.href = tr.dataset.href;
        }
    });
})();
