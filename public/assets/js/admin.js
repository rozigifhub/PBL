/**
 * Fungsionalitas halaman admin (tanpa mengubah desain/tampilan).
 *
 * 1. Preview foto sebelum upload: saat file dipilih pada input[name="foto"],
 *    gambar pratinjau dibuat dinamis di bawah input. Elemen preview memakai
 *    kelas .js-preview-foto agar mudah dikenali/dihapus ulang.
 */

document.addEventListener("DOMContentLoaded", () => {
    const inputFoto = document.querySelector('input[name="foto"]');
    if (!inputFoto) return;

    inputFoto.addEventListener("change", () => {
        // Bersihkan preview sebelumnya
        document.querySelectorAll(".js-preview-foto").forEach((el) => el.remove());

        const file = inputFoto.files && inputFoto.files[0];
        if (!file || !file.type.startsWith("image/")) return;

        const url = URL.createObjectURL(file);
        const img = document.createElement("img");
        img.src = url;
        img.alt = "Preview foto";
        img.className = "js-preview-foto";
        img.style.maxWidth = "160px";
        img.style.display = "block";
        img.style.marginTop = "6px";

        img.onload = () => URL.revokeObjectURL(url);
        inputFoto.after(img);
    });
});
