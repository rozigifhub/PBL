/* Animasi & perilaku kecil untuk halaman login/register. */
(() => {
  // Getar singkat pada elemen (dipakai untuk kolom kosong & pesan error)
  const shake = (el) => {
    el.classList.remove('shake'); void el.offsetWidth; // restart animasi
    el.classList.add('shake');
    setTimeout(() => el.classList.remove('shake'), 450);
  };

  // Efek ripple saat tombol ditekan
  document.querySelectorAll('.btn').forEach((btn) => {
    btn.addEventListener('pointerdown', (e) => {
      const r = btn.getBoundingClientRect(), d = Math.max(r.width, r.height);
      const dot = document.createElement('span');
      dot.className = 'ripple';
      dot.style.cssText = `width:${d}px;height:${d}px;left:${e.clientX - r.left - d / 2}px;top:${e.clientY - r.top - d / 2}px`;
      btn.appendChild(dot);
      dot.addEventListener('animationend', () => dot.remove());
    });
  });

  document.querySelectorAll('form[data-auth]').forEach((form) => {
    // Kolom belum valid -> kotaknya bergetar
    form.addEventListener('invalid', (e) => shake(e.target.closest('.input-box') || e.target), true);

    // Form valid dikirim -> tombol berubah jadi spinner (cegah klik ganda)
    form.addEventListener('submit', (e) => {
      const btn = form.querySelector('.btn');
      if (btn.classList.contains('is-loading')) return e.preventDefault();
      btn.classList.add('is-loading');
    });
  });

  // Tombol "Back" browser: kembalikan tombol ke keadaan normal
  window.addEventListener('pageshow', () =>
    document.querySelectorAll('.btn.is-loading').forEach((b) => b.classList.remove('is-loading')));

  document.querySelectorAll('.alert--error').forEach(shake);
})();
