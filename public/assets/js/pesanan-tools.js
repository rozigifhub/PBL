/**
 * Alat bersama daftar pesanan — dipakai mahasiswa/riwayat.php dan admin/pesanan.php.
 * Hanya mengatur tampilan di browser; data & query PHP tidak disentuh.
 *
 * 1. Dropdown filter status ([data-pt-dropdown]).
 *      data-pt-mode="client" (mahasiswa): opsi berupa <button>; baris disaring di browser lewat data-status.
 *      data-pt-mode="server" (admin)    : opsi berupa <a href="?status=...">; filter tetap dijalankan query backend,
 *                                         JS hanya membuka/menutup menu.
 *    Keyboard: Enter/Spasi/Panah bawah membuka, Panah atas/bawah/Home/End berpindah opsi, Esc menutup.
 * 2. Pencarian ([data-pt-search]): menyaring baris lewat atribut data-search (semua kata harus cocok, tanpa peduli huruf besar/kecil).
 * 3. Pilih Semua ([data-pt-all]) hanya memilih baris yang sedang tampil; baris yang tersaring otomatis tidak lagi terpilih.
 * 4. "Hapus Yang Dipilih" ([data-pt-bulk]): memancarkan event "pesanan:hapus-terpilih" (detail.ids = daftar id pesanan;
 *    preventDefault = ditangani pihak lain). Bila ada form [data-pt-form] di halaman, tombol ini meminta konfirmasi
 *    lewat dialog, mengisi ids[] ke form tersebut, lalu mengirimnya ke server (POST biasa + redirect).
 *    Hanya pesanan dengan data-bisa-hapus="1" (Selesai/Dibatalkan) yang dikirim; server tetap memeriksa ulang.
 *    Tanpa form, muncul catatan bahwa data belum diubah.
 */
(() => {
    "use strict";

    const root = document.querySelector("[data-pt]");
    if (!root) return;

    const $  = (sel, ctx = root) => ctx.querySelector(sel);
    const $$ = (sel, ctx = root) => Array.from(ctx.querySelectorAll(sel));

    const modeClient = root.dataset.ptMode === "client";
    const kunciAwal  = "semua";

    const state = { query: "", daftar: null, label: root.dataset.ptLabel || "" };

    // ---------- 1. Dropdown ----------
    const dd      = $("[data-pt-dropdown]");
    const trigger = dd ? $("[data-pt-trigger]", dd) : null;
    const menu    = dd ? $("[data-pt-menu]", dd) : null;
    const labelEl = dd ? $("[data-pt-filter-label]", dd) : null;
    const items   = menu ? $$("[role^='menuitem']", menu) : [];

    const terbuka = () => !!dd && dd.classList.contains("is-open");

    const buka = (fokusOpsi) => {
        if (!dd || terbuka()) return;
        dd.classList.add("is-open");
        trigger.setAttribute("aria-expanded", "true");
        if (fokusOpsi) {
            const target = items.find((i) => i.getAttribute("aria-checked") === "true") || items[0];
            if (target) target.focus();
        }
    };

    const tutup = (fokusTombol) => {
        if (!terbuka()) return;
        dd.classList.remove("is-open");
        trigger.setAttribute("aria-expanded", "false");
        if (fokusTombol) trigger.focus();
    };

    if (dd && trigger && menu) {
        trigger.addEventListener("click", (e) => {
            if (terbuka()) tutup(false);
            else buka(e.detail === 0);                 // detail 0 = diaktifkan lewat keyboard: langsung fokus ke opsi
        });
        trigger.addEventListener("keydown", (e) => {
            if (e.key === "ArrowDown" || e.key === "ArrowUp") { e.preventDefault(); buka(true); }
        });

        menu.addEventListener("keydown", (e) => {
            const i = items.indexOf(document.activeElement);
            let tujuan = null;
            if (e.key === "ArrowDown") tujuan = items[(i + 1) % items.length];
            else if (e.key === "ArrowUp") tujuan = items[(i - 1 + items.length) % items.length];
            else if (e.key === "Home") tujuan = items[0];
            else if (e.key === "End") tujuan = items[items.length - 1];
            else if (e.key === "Escape") { e.preventDefault(); tutup(true); return; }
            else if (e.key === "Tab") { tutup(false); return; }
            if (tujuan) { e.preventDefault(); tujuan.focus(); }
        });

        document.addEventListener("click", (e) => { if (terbuka() && !dd.contains(e.target)) tutup(false); });
        document.addEventListener("keydown", (e) => { if (e.key === "Escape" && terbuka()) tutup(true); });
    }

    // ---------- Tabel, pencarian, pilihan ----------
    const tabel    = $("[data-pt-table]");
    const baris    = tabel ? $$("tbody tr", tabel) : [];
    const bungkus  = $("[data-pt-wrap]");
    const kosong   = $("[data-pt-empty]");
    const teksKosong = $("[data-pt-empty-text]");
    const info     = $("[data-pt-info]");
    const cari     = $("[data-pt-search]");
    const hitung   = $("[data-pt-count]");
    const semua    = $("[data-pt-all]");
    const hapus    = $("[data-pt-bulk]");
    const catatan  = $("[data-pt-note]");
    const formHapus = $("[data-pt-form]");

    // Samakan huruf besar/kecil & aksen supaya "kaos" cocok dengan "Kaos"
    const norm = (s) => String(s || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();

    baris.forEach((tr) => {
        tr._cari = norm(tr.dataset.search);
        tr._cek  = tr.querySelector("[data-pt-row-check]");
    });

    const barisTampil = () => baris.filter((tr) => !tr.hidden);
    const terpilih    = () => barisTampil().filter((tr) => tr._cek && tr._cek.checked);

    const perbaruiPilihan = () => {
        const tampil = barisTampil().filter((tr) => tr._cek);
        const dipilih = tampil.filter((tr) => tr._cek.checked).length;

        baris.forEach((tr) => { if (tr._cek) tr.classList.toggle("is-selected", tr._cek.checked); });
        if (semua) {
            semua.disabled = tampil.length === 0;
            semua.checked = tampil.length > 0 && dipilih === tampil.length;
            semua.indeterminate = dipilih > 0 && dipilih < tampil.length;
        }
        if (hapus) {
            hapus.disabled = dipilih === 0;
            hapus.title = dipilih > 0 ? "Hapus " + dipilih + " pesanan terpilih" : "";
        }
        if (catatan) catatan.hidden = true;
    };

    const susunPesanKosong = () => {
        const q = cari ? cari.value.trim() : "";
        if (q && state.label) return "Tidak ada hasil untuk \u201C" + q + "\u201D dengan status " + state.label + ".";
        if (q) return "Tidak ada hasil untuk \u201C" + q + "\u201D.";
        if (state.label) return "Tidak ada pesanan dengan status " + state.label + ".";
        return "Tidak ada pesanan.";
    };

    const terapkan = (animasi) => {
        const kata = norm(state.query).split(/\s+/).filter(Boolean);
        let tampil = 0;

        baris.forEach((tr) => {
            const cocokStatus = !state.daftar || state.daftar.includes(tr.dataset.status);
            const cocokCari   = kata.every((k) => tr._cari.includes(k));
            const cocok = cocokStatus && cocokCari;

            tr.hidden = !cocok;
            tr.classList.remove("is-enter");
            if (cocok) {
                tr.style.setProperty("--i", tampil);
                tampil++;
            } else if (tr._cek) {
                tr._cek.checked = false;                 // baris yang tersaring tidak ikut terpilih
            }
        });

        if (animasi && tabel) {
            void tabel.offsetWidth;                      // paksa reflow agar animasi muncul bisa diulang
            baris.forEach((tr) => { if (!tr.hidden) tr.classList.add("is-enter"); });
        }

        if (bungkus) bungkus.hidden = tampil === 0;
        if (kosong) kosong.hidden = tampil !== 0;
        if (teksKosong) teksKosong.textContent = susunPesanKosong();
        if (hitung) hitung.textContent = tampil;
        if (info) info.textContent = tampil === 0 ? susunPesanKosong() : "Menampilkan " + tampil + " pesanan" + (state.label ? " dengan status " + state.label : "") + ".";

        perbaruiPilihan();
    };

    // ---------- Filter status (mode client) ----------
    const pilihStatus = (item, { animasi = true, fokus = false } = {}) => {
        items.forEach((i) => i.setAttribute("aria-checked", i === item ? "true" : "false"));
        state.daftar = item.dataset.filter ? item.dataset.filter.split("|") : null;
        state.label  = item.dataset.key === kunciAwal ? "" : item.dataset.label;
        if (labelEl) labelEl.textContent = item.dataset.label;

        terapkan(animasi);
        tutup(fokus);

        // Simpan pilihan di hash (tanpa menambah riwayat browser) supaya tetap terpilih saat kembali dari halaman detail
        const hash = item.dataset.key === kunciAwal ? "" : "#" + item.dataset.key;
        history.replaceState(null, "", location.pathname + location.search + hash);
    };

    if (modeClient) {
        items.forEach((item) => item.addEventListener("click", () => pilihStatus(item, { fokus: true })));
    }

    // ---------- Pencarian ----------
    if (cari) {
        state.query = cari.value;
        cari.addEventListener("input", () => { state.query = cari.value; terapkan(false); });
    }

    // ---------- Pilih Semua / per baris ----------
    if (semua) {
        semua.addEventListener("change", () => {
            barisTampil().forEach((tr) => { if (tr._cek) tr._cek.checked = semua.checked; });
            perbaruiPilihan();
        });
    }
    baris.forEach((tr) => { if (tr._cek) tr._cek.addEventListener("change", perbaruiPilihan); });

    // ---------- Hapus Yang Dipilih ----------
    const tampilkanCatatan = (teks) => {
        if (!catatan) return;
        catatan.textContent = teks;
        catatan.hidden = false;
    };

    // Dialog konfirmasi (<dialog> bawaan browser: fokus terkunci di dalam dialog, Esc = batal).
    const konfirmasiHapus = (jumlah, dilewati, keterangan) => new Promise((selesai) => {
        const judul = "Hapus " + jumlah + " pesanan?";
        let pesan = keterangan || "";
        if (dilewati > 0) {
            pesan += (pesan ? " " : "") + dilewati + " pesanan lain masih berjalan dan tidak ikut dihapus (hanya status Selesai atau Dibatalkan yang bisa dihapus).";
        }

        if (typeof HTMLDialogElement === "undefined") {          // browser lama: pakai confirm() bawaan
            selesai(window.confirm(judul + (pesan ? "\n\n" + pesan : "")));
            return;
        }

        const dlg = document.createElement("dialog");
        dlg.className = "pt-dialog";
        dlg.setAttribute("aria-labelledby", "pt-dialog-judul");
        dlg.setAttribute("aria-describedby", "pt-dialog-pesan");

        const h = document.createElement("h2");
        h.id = "pt-dialog-judul";
        h.className = "pt-dialog__title";
        h.textContent = judul;

        const p = document.createElement("p");
        p.id = "pt-dialog-pesan";
        p.className = "pt-dialog__text";
        p.textContent = pesan;

        const aksi = document.createElement("div");
        aksi.className = "pt-dialog__actions";
        const batal = document.createElement("button");
        batal.type = "button";
        batal.className = "pt-dialog__btn";
        batal.textContent = "Batal";
        const ya = document.createElement("button");
        ya.type = "button";
        ya.className = "pt-dialog__btn pt-dialog__btn--danger";
        ya.textContent = "Hapus";
        aksi.append(batal, ya);

        dlg.append(h, p, aksi);
        document.body.appendChild(dlg);

        let hasil = false;
        batal.addEventListener("click", () => dlg.close());
        ya.addEventListener("click", () => { hasil = true; dlg.close(); });
        dlg.addEventListener("click", (e) => { if (e.target === dlg) dlg.close(); });      // klik latar = batal
        dlg.addEventListener("close", () => { dlg.remove(); selesai(hasil); if (hapus) hapus.focus(); });

        dlg.showModal();
        batal.focus();                                                                     // fokus awal di aksi yang aman
    });

    if (hapus) {
        hapus.addEventListener("click", () => {
            const dipilih = terpilih();
            const ids = dipilih.map((tr) => tr._cek.value);
            if (ids.length === 0) return;

            const belumDitangani = root.dispatchEvent(new CustomEvent("pesanan:hapus-terpilih", { bubbles: true, cancelable: true, detail: { ids } }));
            if (!belumDitangani) return;

            if (!formHapus) {
                tampilkanCatatan(ids.length + " pesanan dipilih. Fitur hapus pesanan belum tersambung ke server, jadi data tidak diubah.");
                return;
            }

            const layak = dipilih.filter((tr) => tr.dataset.bisaHapus === "1");
            const dilewati = dipilih.length - layak.length;
            if (layak.length === 0) {
                tampilkanCatatan("Pesanan yang dipilih masih berjalan. Hanya pesanan berstatus Selesai atau Dibatalkan yang bisa dihapus.");
                return;
            }

            konfirmasiHapus(layak.length, dilewati, formHapus.dataset.ptKonfirmasi).then((setuju) => {
                if (!setuju) return;
                $$("input[name='ids[]']", formHapus).forEach((el) => el.remove());
                layak.forEach((tr) => {
                    const input = document.createElement("input");
                    input.type = "hidden";
                    input.name = "ids[]";
                    input.value = tr._cek.value;
                    formHapus.appendChild(input);
                });
                hapus.disabled = true;                 // cegah klik ganda selama halaman dimuat ulang
                formHapus.submit();
            });
        });
    }

    // ---------- Pencarian/Reset ----------
    $$("[data-pt-reset]").forEach((tombol) => {
        tombol.addEventListener("click", () => {
            if (cari) { cari.value = ""; state.query = ""; }
            if (modeClient) {
                const awal = items.find((i) => i.dataset.key === kunciAwal) || items[0];
                if (awal) { pilihStatus(awal, { fokus: false }); if (cari) cari.focus(); return; }
            }
            terapkan(false);
            if (cari) cari.focus();
        });
    });

    // ---------- Awal ----------
    if (modeClient && items.length) {
        const kunciHash = location.hash.replace(/^#/, "");
        const awal = items.find((i) => i.dataset.key === kunciHash) || items.find((i) => i.dataset.key === kunciAwal) || items[0];
        pilihStatus(awal, { fokus: false });
    } else {
        terapkan(false);
    }

    // Animasi masuk bawaan baris cukup sekali (lihat pesanan-tools.css)
    if (tabel) setTimeout(() => tabel.classList.add("pt-settled"), 1500);
})();