/* =========================================================
   KERANJANG BELANJA  (mahasiswa/keranjang.php)
   Tanpa library. Semua elemen dicari lewat atribut data-* yang dicetak keranjang.php.

   - Pilih Semua / Hapus Yang Dipilih (checkbox tiap item terhubung ke form lewat atribut form="...")
   - Tombol - / + mengubah jumlah, tersimpan otomatis ke server (action=update lewat AJAX)
     dan ringkasan belanja dihitung ulang seketika.
   - "Lanjut Ke Pembayaran" menunggu perubahan jumlah yang belum tersimpan sebelum pindah ke checkout.php,
     sehingga checkout selalu membaca jumlah yang sama dengan yang terlihat di layar.
   Tanpa JavaScript, halaman tetap bisa dipakai: tombol "Perbarui" (noscript) dan hapus per item tetap jalan.
   ========================================================= */
(() => {
  'use strict';

  const root = document.querySelector('[data-cart]');
  if (!root) return;

  const ONGKIR   = Number(root.dataset.ongkir) || 0; // estimasi ongkir (diatur di keranjang.php)
  const DEBOUNCE = 450;                              // jeda (ms) sebelum perubahan jumlah dikirim

  const $  = (sel, el = root) => el.querySelector(sel);
  const $$ = (sel, el = root) => Array.from(el.querySelectorAll(sel));
  const clamp  = (n, lo, hi) => Math.min(Math.max(n, lo), hi);
  const rupiah = (n) => 'Rp. ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const warnText = (stok) => stok < 1
    ? 'Stok habis — hapus item ini dari keranjang.'
    : 'Stok tidak cukup (tersedia ' + stok + ')';

  const selectAll = $('[data-select-all]');
  const bulkBtn   = $('[data-bulk-delete]');
  const bulkForm  = document.getElementById('form-hapus-terpilih');
  const cta       = $('[data-cta]');
  const ctaWarn   = $('[data-cta-warn]');
  const toastBox  = $('[data-toast]');
  const sumCount  = $('[data-sum-count]');
  const sumSub    = $('[data-sum-subtotal]');
  const sumTotal  = $('[data-sum-total]');

  /* ---------- Pesan singkat ---------- */
  function toast(msg, type) {
    if (!msg || !toastBox) return;
    const el = document.createElement('div');
    el.className = 'cart-toast__msg' + (type === 'err' ? ' cart-toast__msg--err' : '');
    el.textContent = msg;
    toastBox.appendChild(el);
    while (toastBox.children.length > 3) toastBox.firstChild.remove();
    setTimeout(() => el.remove(), 3600);
  }

  /* ---------- Data tiap item ---------- */
  const items = new Map(); // id -> state

  function initItem(el) {
    const input = $('.cart-stepper__input', el);
    input.value = input.defaultValue;            // abaikan nilai sisa dari cache form browser
    const qty = parseInt(input.value, 10) || 1;
    return {
      el,
      id: el.dataset.id,
      form: $('[data-qty-form]', el),
      input,
      harga: Number(el.dataset.harga) || 0,
      stok: Number(el.dataset.stok) || 0,
      qty,            // jumlah yang sedang tampil
      saved: qty,     // jumlah terakhir yang sudah dikonfirmasi server
      timer: null,    // timer debounce
      busy: null,     // Promise permintaan yang sedang berjalan
    };
  }

  const stateOf = (node) => {
    const li = node.closest('[data-item]');
    return li ? items.get(li.dataset.id) : undefined;
  };

  /* ---------- Hitung ulang tampilan ---------- */
  function render() {
    let subtotal = 0;
    let masalahStok = false;

    items.forEach((st) => {
      const line  = st.harga * st.qty;
      const short = st.qty > st.stok;
      subtotal += line;
      if (short) masalahStok = true;

      st.el.classList.toggle('is-short', short);

      const warn = $('[data-warn]', st.el);
      if (warn) {
        warn.hidden = !short;
        warn.textContent = warnText(st.stok);
      }

      const totalEl = $('[data-subtotal]', st.el);
      const teks = rupiah(line);
      if (totalEl && totalEl.textContent !== teks) {
        totalEl.textContent = teks;
        totalEl.classList.remove('is-bump');
        void totalEl.offsetWidth;                // ulang animasi
        totalEl.classList.add('is-bump');
      }

      const tombol = $$('.cart-stepper__btn', st.el);
      if (tombol.length === 2) {
        const habis = st.stok < 1;
        tombol[0].disabled = habis || st.qty <= 1;
        tombol[1].disabled = habis || st.qty >= st.stok;
      }
    });

    const n = items.size;
    if (sumCount) sumCount.textContent = n;
    if (sumSub)   sumSub.textContent   = rupiah(subtotal);
    if (sumTotal) sumTotal.textContent = rupiah(subtotal + (n ? ONGKIR : 0));

    if (cta) {
      cta.classList.toggle('is-disabled', masalahStok);
      if (masalahStok) {
        cta.setAttribute('aria-disabled', 'true');
        cta.tabIndex = -1;
      } else {
        cta.removeAttribute('aria-disabled');
        cta.removeAttribute('tabindex');
      }
    }
    if (ctaWarn) ctaWarn.hidden = !masalahStok;
  }

  /* ---------- Pilih Semua / Hapus Yang Dipilih ---------- */
  const checks = () => $$('.cart-item__check');

  function syncSelection() {
    const semua = checks();
    const dipilih = semua.filter((c) => c.checked);

    if (selectAll) {
      selectAll.checked = semua.length > 0 && dipilih.length === semua.length;
      selectAll.indeterminate = dipilih.length > 0 && dipilih.length < semua.length;
    }
    if (bulkBtn) bulkBtn.disabled = dipilih.length === 0;

    semua.forEach((c) => c.closest('[data-item]').classList.toggle('is-selected', c.checked));
    $$('[data-count]').forEach((el) => { el.textContent = semua.length; });
  }

  if (selectAll) {
    selectAll.addEventListener('change', () => {
      checks().forEach((c) => { c.checked = selectAll.checked; });
      syncSelection();
    });
  }

  /* ---------- Ubah jumlah ---------- */
  function setQty(st, nilai) {
    let q = Number.isNaN(nilai) ? st.saved : nilai;
    q = clamp(q, 1, Math.max(st.stok, 1));
    st.qty = q;
    st.input.value = q;
    render();
    schedule(st);
  }

  function schedule(st) {
    clearTimeout(st.timer);
    st.timer = null;
    if (st.qty === st.saved) return;             // kembali ke nilai tersimpan: tidak perlu kirim
    st.timer = setTimeout(() => save(st), DEBOUNCE);
  }

  function save(st) {
    clearTimeout(st.timer);
    st.timer = null;
    if (st.busy) return st.busy;                 // sedang menyimpan; akan dicek lagi di finally

    const dikirim = st.qty;
    st.el.classList.add('is-saving');

    st.busy = (async () => {
      try {
        const body = new FormData(st.form);      // csrf_token, action=update, id_merchandise
        body.set('jumlah', String(dikirim));

        // getAttribute: form.action tertimpa oleh <input name="action"> (DOM clobbering)
        const res = await fetch(st.form.getAttribute('action') || window.location.pathname, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });

        let data = null;
        try { data = await res.json(); } catch (_) { /* bukan JSON: sesi habis atau error server */ }
        if (!data) throw new Error('Sesi berakhir atau server bermasalah. Muat ulang halaman ini.');
        if (!res.ok || !data.ok) throw new Error(data.message || 'Jumlah belum tersimpan. Coba lagi.');

        applyServer(st, data, dikirim);
      } catch (err) {
        revert(st);
        toast(err instanceof TypeError
          ? 'Tidak dapat terhubung ke server. Periksa koneksi Anda.'
          : err.message, 'err');
      } finally {
        st.busy = null;
        st.el.classList.remove('is-saving');
        if (st.el.isConnected && st.qty !== st.saved) schedule(st); // ada perubahan baru saat menyimpan
      }
    })();

    return st.busy;
  }

  function applyServer(st, data, dikirim) {
    if (data.removed) {
      removeItem(st);
      toast(data.message, data.type === 'gagal' ? 'err' : 'ok');
      return;
    }

    st.stok = Number(data.stok) || 0;
    st.el.dataset.stok = st.stok;
    st.input.max = st.stok;
    st.saved = Number(data.jumlah);

    if (st.qty === dikirim) {                    // pengguna belum mengubah lagi: ikuti nilai server
      st.qty = st.saved;
      st.input.value = st.saved;
    }
    render();

    if (data.type === 'gagal') toast(data.message, 'err'); // mis. jumlah disesuaikan dengan stok
  }

  function revert(st) {
    st.qty = st.saved;
    st.input.value = st.saved;
    render();
  }

  function removeItem(st) {
    clearTimeout(st.timer);
    items.delete(st.id);
    st.el.classList.add('is-leaving');
    setTimeout(() => {
      st.el.remove();
      if (items.size === 0) { location.reload(); return; } // tampilkan keadaan "keranjang kosong"
      syncSelection();
      render();
    }, 260);
  }

  /* ---------- Tunggu perubahan yang belum tersimpan ---------- */
  const hasPending = () => Array.from(items.values()).some((st) => st.timer || st.busy);

  async function flush() {
    for (let i = 0; i < 4 && hasPending(); i++) {
      const jobs = [];
      items.forEach((st) => {
        if (st.timer) {
          clearTimeout(st.timer);
          st.timer = null;
          if (st.qty !== st.saved) jobs.push(save(st));
        } else if (st.busy) {
          jobs.push(st.busy);
        }
      });
      await Promise.all(jobs);
    }
  }

  const afterFlush = (fn) => { flush().then(fn); };

  /* ---------- Event ---------- */
  root.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-step]');
    if (!btn || btn.disabled) return;
    const st = stateOf(btn);
    if (st) setQty(st, st.qty + Number(btn.dataset.step));
  });

  root.addEventListener('input', (e) => {
    if (!e.target.matches('.cart-stepper__input')) return;
    const st = stateOf(e.target);
    const v = parseInt(e.target.value, 10);
    if (st && !Number.isNaN(v)) {                // pratinjau langsung saat mengetik
      st.qty = clamp(v, 1, Math.max(st.stok, 1));
      render();
    }
  });

  root.addEventListener('change', (e) => {
    if (e.target.matches('.cart-stepper__input')) {
      const st = stateOf(e.target);
      if (st) setQty(st, parseInt(e.target.value, 10));
    } else if (e.target.matches('.cart-item__check')) {
      syncSelection();
    }
  });

  root.addEventListener('submit', (e) => {
    const form = e.target;

    // Enter pada kolom jumlah: simpan lewat AJAX, bukan kirim form penuh
    if (form.matches('[data-qty-form]')) {
      e.preventDefault();
      const st = stateOf(form);
      if (st) { setQty(st, parseInt(st.input.value, 10)); flush(); }
      return;
    }

    // Hapus satu item
    if (form.dataset.confirm !== undefined) {
      e.preventDefault();
      if (!window.confirm(form.dataset.confirm)) return;
      afterFlush(() => form.submit());
      return;
    }

    // Hapus Yang Dipilih
    if (form === bulkForm) {
      e.preventDefault();
      const n = checks().filter((c) => c.checked).length;
      if (n === 0) { toast('Pilih produk yang ingin dihapus.', 'err'); return; }
      if (!window.confirm('Hapus ' + n + ' item yang dipilih dari keranjang?')) return;
      afterFlush(() => bulkForm.submit());
    }
  });

  // Lanjut ke checkout: pastikan jumlah sudah tersimpan & tidak ada masalah stok
  if (cta) {
    cta.addEventListener('click', (e) => {
      if (cta.classList.contains('is-disabled')) {
        e.preventDefault();
        toast('Perbarui jumlah item bertanda merah dulu sebelum melanjutkan.', 'err');
        return;
      }
      if (hasPending()) {
        e.preventDefault();
        flush().then(() => {
          if (cta.classList.contains('is-disabled')) {
            toast('Jumlah disesuaikan dengan stok. Periksa kembali keranjang Anda.', 'err');
          } else {
            window.location.href = cta.href;
          }
        });
      }
    });
  }

  // Kembali lewat tombol Back (bfcache): samakan lagi status centang
  window.addEventListener('pageshow', (e) => { if (e.persisted) syncSelection(); });

  /* ---------- Mulai ---------- */
  $$('[data-item]').forEach((el) => items.set(el.dataset.id, initItem(el)));
  syncSelection();
  render();
})();
