/* Katalog: pencarian instan (di browser, tanpa mengubah query PHP) + tombol keranjang. */
(() => {
  const input = document.getElementById('cari-produk');
  const cards = [...document.querySelectorAll('.pcard')];
  const kosong = document.getElementById('cari-kosong');

  // Cari berdasarkan nama / kategori / ukuran (diambil dari atribut data-cari tiap kartu)
  if (input) {
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let cocok = 0;
      cards.forEach((c) => {
        const ok = c.dataset.cari.toLowerCase().includes(q);
        c.hidden = !ok;
        if (ok) cocok++;
      });
      if (kosong) kosong.hidden = cocok > 0 || cards.length === 0;
    });
  }

  // Tombol "+ Keranjang": beri umpan balik & cegah klik ganda
  document.querySelectorAll('.pcard form').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const btn = form.querySelector('.pbtn'), label = btn.querySelector('span');
      if (btn.classList.contains('is-loading')) return e.preventDefault();
      btn.dataset.label = label.textContent;
      label.textContent = 'Menambah…';
      btn.classList.add('is-loading');
    });
  });

  // Tombol Back browser: kembalikan tombol seperti semula
  window.addEventListener('pageshow', () =>
    document.querySelectorAll('.pbtn.is-loading').forEach((b) => {
      b.classList.remove('is-loading');
      b.querySelector('span').textContent = b.dataset.label || '+ Keranjang';
    }));
})();
