/**
 * Kelola Produk Merchandise admin (admin/merchandise.php) — interaksi kecil, tanpa mengubah data.
 * Semua fitur bersifat tambahan: tanpa JS, form tambah/ubah, upload foto, tombol Edit, dan tombol Hapus
 * (beserta konfirmasi di atribut onsubmit) tetap berfungsi seperti dari tim backend.
 *
 * 1. Kotak Deskripsi Produk membesar ke bawah otomatis mengikuti panjang teks (tanpa handle resize).
 * 2. Upload foto: nama file + pratinjau muncul di dalam kotak, animasi saat file berhasil dipilih,
 *    sorotan saat file diseret ke atas kotak, dan pengecekan awal format/ukuran (server tetap memeriksa ulang).
 *    Fungsi ini menggantikan pratinjau lama di admin.js (admin.js tidak dimuat lagi di halaman ini).
 * 3. Tombol kirim dikunci setelah form dikirim supaya upload foto tidak terkirim dua kali.
 * 4. Mode ubah (?edit=ID): kolom nama langsung terfokus.
 * 5. Menu akun di navbar menutup saat klik di luar menu atau menekan Esc
 *    (sama dengan perilaku di admin-dashboard.js, admin-pesanan.js, dan admin-kategori.js).
 */
(() => {
    "use strict";

    const MAKS_BYTE = 2 * 1024 * 1024;                          // sama dengan MAX_FOTO_BYTE di merchandise.php
    const TIPE_OK   = ["image/jpeg", "image/png", "image/webp"]; // sama dengan MIME_DIIZINKAN di merchandise.php

    // ---------- 1. Deskripsi: tinggi mengikuti isi ----------
    const area = document.querySelector(".mp-textarea");
    if (area) {
        const sesuaikan = () => {
            area.style.height = "auto";   // ukur ulang dari tinggi minimum (CSS) supaya bisa mengecil lagi saat teks dihapus
            // scrollHeight tidak memuat garis tepi (kotak memakai border-box), jadi ditambahkan manual
            area.style.height = (area.scrollHeight + (area.offsetHeight - area.clientHeight)) + "px";
        };

        area.classList.add("is-auto");   // sembunyikan scrollbar: tinggi sudah selalu cukup
        area.addEventListener("input", sesuaikan);
        window.addEventListener("resize", sesuaikan);                // lebar berubah -> baris terlipat berbeda
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(sesuaikan);
        sesuaikan();                                                  // mode edit: deskripsi lama langsung terukur
    }

    // ---------- 2. Upload foto ----------
    const kotak = document.querySelector(".mp-file");
    if (kotak) {
        const input = kotak.querySelector(".mp-file__input");
        const nama  = kotak.querySelector(".mp-file__name");
        const thumb = kotak.querySelector(".mp-file__thumb");

        // Keadaan awal dari PHP (mode edit: "foto saat ini" + thumbnail-nya) untuk dipulihkan bila pilihan dibatalkan
        const awal = {
            teks:  nama.textContent,
            thumb: thumb.getAttribute("src") || "",
            alt:   thumb.getAttribute("alt") || "",
        };
        let urlSementara = null;

        const lepasUrl = () => {
            if (urlSementara) {
                URL.revokeObjectURL(urlSementara);
                urlSementara = null;
            }
        };

        const pulihkan = () => {
            lepasUrl();
            nama.textContent = awal.teks;
            nama.removeAttribute("title");
            if (awal.thumb) {
                thumb.src = awal.thumb;
                thumb.alt = awal.alt;
                kotak.classList.add("has-thumb");
            } else {
                thumb.removeAttribute("src");
                kotak.classList.remove("has-thumb");
            }
        };

        input.addEventListener("change", () => {
            kotak.classList.remove("is-selected", "is-invalid", "is-just-chosen");

            const berkas = input.files && input.files[0];
            if (!berkas) {          // dialog dibatalkan / pilihan dikosongkan
                pulihkan();
                return;
            }

            let pesan = "";
            if (berkas.type && !TIPE_OK.includes(berkas.type)) {
                pesan = "Format foto harus JPG, PNG, atau WEBP.";
            } else if (berkas.size > MAKS_BYTE) {
                pesan = "Ukuran foto maksimal 2 MB.";
            }

            if (pesan) {
                input.value = "";   // buang pilihan; di mode tambah, atribut "required" menahan pengiriman form
                pulihkan();
                nama.textContent = pesan;
                kotak.classList.add("is-invalid");
                return;
            }

            lepasUrl();
            urlSementara = URL.createObjectURL(berkas);
            thumb.src = urlSementara;
            thumb.alt = "Pratinjau foto produk";
            nama.textContent = berkas.name;
            nama.title = berkas.name;
            kotak.classList.add("has-thumb", "is-selected");

            // Jalankan ulang animasi "berhasil dipilih" (class dilepas dulu agar animasi bisa mulai dari awal)
            void kotak.offsetWidth;
            kotak.classList.add("is-just-chosen");
            setTimeout(() => kotak.classList.remove("is-just-chosen"), 800);
        });

        // Sorotan saat file diseret ke atas kotak (input file transparan menutupi kotak, jadi event-nya ada di input)
        ["dragenter", "dragover"].forEach((ev) => input.addEventListener(ev, () => kotak.classList.add("is-drag")));
        ["dragleave", "dragend", "drop"].forEach((ev) => input.addEventListener(ev, () => kotak.classList.remove("is-drag")));
    }

    // ---------- 3. Kunci tombol kirim setelah submit ----------
    const form  = document.querySelector(".mp-form");
    const kirim = form ? form.querySelector(".mp-add") : null;
    if (form && kirim) {
        form.addEventListener("submit", () => {
            // Event "submit" hanya muncul setelah validasi bawaan browser lolos
            kirim.classList.add("is-busy");
            kirim.setAttribute("aria-busy", "true");
            kirim.disabled = true;
        });
        // Tombol "kembali" browser memulihkan halaman dari cache: buka kuncinya lagi
        window.addEventListener("pageshow", (e) => {
            if (e.persisted) {
                kirim.disabled = false;
                kirim.classList.remove("is-busy");
                kirim.removeAttribute("aria-busy");
            }
        });
    }

    // ---------- 4. Mode ubah: fokus ke kolom nama ----------
    const kolomNama = document.querySelector('.mp-form[data-mode="ubah"] [name="nama_merchandise"]');
    if (kolomNama) {
        kolomNama.focus({ preventScroll: true });
    }

    // ---------- 5. Menu akun ----------
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
