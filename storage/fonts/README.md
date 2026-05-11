# Folder Font dompdf

Direktori ini berisi berkas font TTF yang dipakai dompdf untuk merender 20
template PDF elegan (RoPA / DPIA / GAP). dompdf membutuhkan font lokal —
font yang dideklarasikan via `@font-face` dengan URL remote tidak selalu
diunduh dengan benar saat render.

## Cara Mengisi Folder Ini

Jalankan perintah artisan berikut sekali saja pada lingkungan deployment
Anda (lokal, staging, maupun produksi):

```bash
php artisan templates:install-fonts
```

Tambahkan flag `--force` untuk menimpa berkas yang sudah ada:

```bash
php artisan templates:install-fonts --force
```

Perintah tersebut mengunduh keenam keluarga font di bawah ini dari
Google Webfonts Helper (`https://gwfh.mranftl.com/`), kemudian
mendaftarkannya ke metrik dompdf supaya siap dipakai oleh Blade view.

## Daftar Font yang Dipakai

| Font | Weight / Style | Lisensi |
|------|----------------|---------|
| Inter | 400, 500, 600, 700, 800, 900 | SIL Open Font License 1.1 |
| Cormorant Garamond | 400, 500, 600, italic 400, italic 500 | SIL Open Font License 1.1 |
| EB Garamond | 400, 500, italic 400 | SIL Open Font License 1.1 |
| Plus Jakarta Sans | 400, 500, 600, 700, 800 | SIL Open Font License 1.1 |
| Playfair Display | 400, 700, 900, italic 400 | SIL Open Font License 1.1 |
| JetBrains Mono | 400, 500 | SIL Open Font License 1.1 |

Seluruh font di atas didistribusikan oleh penerbit aslinya di bawah
SIL Open Font License 1.1 — bebas dipakai secara komersial, dapat
digabung (embed) ke berkas PDF tanpa kewajiban royalti, dan dapat
didistribusikan ulang selama nama font asli tetap dipertahankan.

## Sumber

- Inter — https://rsms.me/inter/ (penerbit: Rasmus Andersson)
- Cormorant Garamond — Catharsis Fonts (Christian Thalmann)
- EB Garamond — Georg Duffner
- Plus Jakarta Sans — Tokotype
- Playfair Display — Claus Eggers Sorensen
- JetBrains Mono — JetBrains s.r.o.

## Konfigurasi dompdf

`config/dompdf.php` menunjuk `font_dir` dan `font_cache` ke folder ini.
Setelah berkas TTF tersalin, dompdf otomatis membangun cache metrik
(berkas `.ufm`, `.afm`, atau `dompdf_font_family_cache.php`) pada saat
render pertama, kemudian memakai cache itu pada render berikutnya.

## Catatan

- Jangan menambahkan font dengan format selain TTF — dompdf tidak
  mendukung OTF, WOFF, maupun WOFF2.
- Jangan men-commit berkas font ke repository. `.gitignore` di folder
  ini sudah mengecualikan seluruh `*.ttf`. Hanya `README.md` dan
  `.gitkeep` yang tersimpan di git.
