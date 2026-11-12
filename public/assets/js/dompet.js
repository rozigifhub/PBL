/**
 * Dompet Saya mahasiswa (mahasiswa/dompet.php) — interaksi kecil, tanpa mengubah data form.
 *
 * Tombol nominal cepat (Rp. 20.000, 50.000, ...) di kartu "Metode Pembayaran":
 *   - klik tombol  -> tombol jadi oranye (class .is-active) dan angkanya diisikan ke kolom
 *                     <input name="jumlah"> (kolom "Nominal Lainnya") yang dibaca backend;
 *   - mengetik di kolom "Nominal Lainnya" -> pilihan tombol menyesuaikan (aktif bila angkanya sama, mati bila beda);
 *   - menekan "Top up via Midtrans" saat kolom masih kosong -> angka dari tombol yang sedang aktif dipakai,
 *     jadi tombol bawaan (50.000) tetap bisa langsung dikirim.
 * name, method, csrf_token, dan pengiriman form ke backend TIDAK diubah.
 * Tanpa JS, kolom "Nominal Lainnya" tetap bisa diisi manual.
 */
(() => {
    "use strict";

    const form = document.querySelector("[data-dp-topup]");
    if (!form) return;

    const input = form.elements["jumlah"];
    const pills = Array.from(form.querySelectorAll("[data-dp-nominal]"));
    if (!input || !pills.length) return;

    const aktifkan = (pill) => {
        pills.forEach((p) => {
            const hidup = p === pill;
            p.classList.toggle("is-active", hidup);
            p.setAttribute("aria-pressed", hidup ? "true" : "false");
        });
    };

    const pillAktif = () => pills.find((p) => p.classList.contains("is-active")) || null;

    // Klik tombol nominal: isi kolom jumlah + tandai tombol aktif
    pills.forEach((pill) => {
        pill.addEventListener("click", () => {
            input.value = pill.dataset.dpNominal;
            aktifkan(pill);
        });
    });

    // Mengetik nominal sendiri: tombol aktif hanya bila angkanya persis sama dengan salah satu tombol
    input.addEventListener("input", () => {
        const sama = pills.find((p) => p.dataset.dpNominal === input.value.trim());
        aktifkan(sama || null);
    });

    // Kolom masih kosong saat tombol kirim ditekan: pakai nominal tombol yang aktif.
    // Event "click" pada tombol submit terjadi SEBELUM validasi browser (juga saat menekan Enter), jadi atribut required tetap aman.
    const tombolKirim = form.querySelector("[type='submit']");
    if (tombolKirim) {
        tombolKirim.addEventListener("click", () => {
            const aktif = pillAktif();
            if (input.value.trim() === "" && aktif) {
                input.value = aktif.dataset.dpNominal;
            }
        });
    }
})();
