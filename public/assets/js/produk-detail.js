/* =========================================================
   DETAIL PRODUK MAHASISWA  (mahasiswa/produk.php)
   Tanpa library. Tidak mengubah form/backend: hanya memperbaiki cara pakai tampilan.

   - Stepper jumlah: tombol - dan + mengubah <input name="jumlah"> (min 1, maks = stok dari atribut max).
     Tanpa JavaScript, kolom angka tetap bisa diisi langsung dan form tetap terkirim.
   - Tombol "Tambah ke Keranjang" / "Beli Sekarang" / tombol kecil di "Pilih Lainnya":
     beri umpan balik "Menambah…" dan cegah klik ganda. Data form TIDAK diubah.
   ========================================================= */
(() => {
  'use strict';

  /* ---------- Stepper jumlah ---------- */
  const qty = document.querySelector('[data-qty]');
  if (qty) {
    const input = qty.querySelector('input[type="number"]');
    const minus = qty.querySelector('[data-qty-minus]');
    const plus  = qty.querySelector('[data-qty-plus]');

    const batas = () => ({
      min: parseInt(input.min, 10) || 1,
      max: parseInt(input.max, 10) || Infinity,
    });
    const rapikan = (n) => {
      const { min, max } = batas();
      if (!Number.isFinite(n)) n = min;
      return Math.min(Math.max(n, min), max);
    };
    const tampil = () => {
      const { min, max } = batas();
      const n = parseInt(input.value, 10);
      minus.disabled = Number.isFinite(n) && n <= min;
      plus.disabled  = Number.isFinite(n) && n >= max;
    };
    const set = (n) => { input.value = rapikan(n); tampil(); };

    input.value = input.defaultValue;                    // abaikan nilai sisa dari cache form browser
    minus.addEventListener('click', () => set((parseInt(input.value, 10) || 1) - 1));
    plus.addEventListener('click',  () => set((parseInt(input.value, 10) || 0) + 1));
    input.addEventListener('input',  tampil);
    input.addEventListener('change', () => set(parseInt(input.value, 10)));   // nilai kosong / di luar batas dirapikan
    input.addEventListener('keydown', (e) => {                                 // Enter = kirim form seperti biasa
      if (e.key === 'ArrowUp')   { e.preventDefault(); plus.click(); }
      if (e.key === 'ArrowDown') { e.preventDefault(); minus.click(); }
    });
    tampil();
  }

  /* ---------- Umpan balik tombol saat form dikirim ---------- */
  const tandai = (btn) => {
    const label = btn.querySelector('span');
    if (!label) return;
    btn.dataset.label = label.textContent;
    label.textContent = 'Menambah…';
    btn.classList.add('is-loading');
  };
  // Pakai class (bukan disabled) supaya nama/nilai tombol (mis. beli_sekarang) tetap ikut terkirim.

  const formUtama = document.querySelector('[data-form-keranjang]');
  if (formUtama) {
    formUtama.addEventListener('submit', (e) => {
      if (formUtama.dataset.busy) return e.preventDefault();   // cegah klik ganda
      formUtama.dataset.busy = '1';
      const btn = e.submitter;
      if (btn && btn.classList.contains('pd-btn')) tandai(btn);
    });
  }

  document.querySelectorAll('.pd-card form').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const btn = form.querySelector('.pd-card__btn');
      if (!btn || btn.classList.contains('is-loading')) return e.preventDefault();
      tandai(btn);
    });
  });

  // Tombol Back browser (bfcache): kembalikan tombol seperti semula
  window.addEventListener('pageshow', () => {
    if (formUtama) delete formUtama.dataset.busy;
    document.querySelectorAll('.is-loading').forEach((b) => {
      b.classList.remove('is-loading');
      const label = b.querySelector('span');
      if (label && b.dataset.label) label.textContent = b.dataset.label;
    });
  });
})();
