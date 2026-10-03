<?php
/**
 * Komponen UI yang dipakai ulang di halaman login & register.
 * Ubah tampilan field/ikon cukup di file ini + assets/css/style.css.
 */

/** Ikon SVG inline (warna mengikuti CSS). Tambah ikon baru di array ini. */
function icon(string $name): string
{
    static $icons = [
        'user'  => '<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><circle cx="16" cy="9" r="5.5"/><path d="M5.5 28c0-6 3.5-9 7-9h7c3.5 0 7 3 7 9z"/></svg>',
        'lock'  => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M10 14v-4a6 6 0 0 1 12 0v4" fill="none" stroke="currentColor" stroke-width="2.2"/><rect x="5" y="13" width="22" height="16" rx="2.5" fill="currentColor"/><circle cx="16" cy="19.5" r="2.4" fill="#fff"/><path d="M16 21.5v4" stroke="#fff" stroke-width="1.8"/></svg>',
        'arrow' => '<svg viewBox="0 0 48 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12h43M35 3l10 9-10 9"/></svg>',
    ];
    return $icons[$name] ?? '';
}

/**
 * Cetak satu kolom form (label + input).
 * $name harus sama dengan nama field yang dibaca back-end ($_POST[...]).
 * $opts: type | icon (user, lock) | value | attrs (atribut HTML tambahan, ditulis developer).
 */
function field(string $label, string $name, string $placeholder, array $opts = []): void
{
    static $i = 0; // nomor urut -> animasi muncul bertahap
    $e     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $type  = $opts['type'] ?? 'text';
    $value = $type === 'password' ? '' : ($opts['value'] ?? ''); // password tidak pernah diisi ulang
    $icon  = isset($opts['icon']) ? icon($opts['icon']) : '';

    echo '<div class="field" style="--i:' . $i++ . '">'
       . '<label class="field__label" for="f-' . $e($name) . '">' . $e($label) . '</label>'
       . '<div class="input-box">' . $icon
       . '<input id="f-' . $e($name) . '" type="' . $e($type) . '" name="' . $e($name) . '"'
       . ' placeholder="' . $e($placeholder) . '" value="' . $e($value) . '" required ' . ($opts['attrs'] ?? '') . '>'
       . '</div></div>';
}
