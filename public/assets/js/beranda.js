/* Beranda: tombol "Tambah ke Keranjang" pada kartu Flash Sale.
   Beri umpan balik & cegah klik ganda (pola yang sama dengan katalog.js). */
(() => {
  document.querySelectorAll('.fcard form').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const btn = form.querySelector('.fbtn'), label = btn.querySelector('span');
      if (btn.classList.contains('is-loading')) return e.preventDefault();
      btn.dataset.label = label.textContent;
      label.textContent = 'Menambah…';
      btn.classList.add('is-loading');
    });
  });

  // Tombol Back browser: kembalikan tombol seperti semula
  window.addEventListener('pageshow', () =>
    document.querySelectorAll('.fbtn.is-loading').forEach((b) => {
      b.classList.remove('is-loading');
      b.querySelector('span').textContent = b.dataset.label || 'Tambah ke Keranjang';
    }));
})();
