# Spesifikasi CMS "Website & Konten" (usulan UX-0)

Status: **spesifikasi, belum dibangun.** Dibangun di milestone UX-2 setelah owner menyetujui, termasuk keputusan di §12.

Tujuan: admin mengganti foto dan teks halaman depan tanpa developer. Lingkupnya sengaja kecil, cukup untuk tim satu outlet. Ini bukan CMS umum seperti WordPress: tidak ada editor halaman bebas, HTML, atau plugin.

## 1. Lingkup

| Masuk | Tidak masuk |
|---|---|
| Hero: foto, headline, subheadline, teks CTA, tampil/sembunyi foto | Halaman baru selain `/` |
| Tentang Sudaya: cerita singkat, 1–2 foto | Editor HTML/rich text |
| Menu unggulan: pilih dan urutkan produk dari menu | Harga atau ketersediaan terpisah dari menu |
| Galeri: unggah banyak foto, kategori, urutan, aktif/nonaktif, hapus | Video |
| Pengaturan situs: logo, favicon, nama situs, meta title/description, tautan sosial, kontak, tautan Google Maps | Blog, promo terjadwal, multi-bahasa |
| Draft → pratinjau → terbitkan / batalkan terbit | Riwayat versi lengkap (hanya draft + versi terbit) |
| Jam buka & telepon outlet (di halaman Outlet, kolom yang sudah ada) | Koordinat outlet (dipakai geofence absensi; tidak disentuh) |

## 2. Akses

- **Izin:** memakai `settings.manage` yang sudah ada. Default: hanya Super Admin. Tidak ada perubahan RBAC.
- **Opsi bila owner menginginkan Manager/Owner ikut mengelola konten:** permission baru `content.manage`. Ini perubahan RBAC dan butuh persetujuan terpisah (§12).
- Semua aksi dicek di server (middleware `AuthorizeRoute` + cek di service), scope company/brand, seperti modul katalog.
- Service role key hanya di server. Browser tidak pernah mengunggah langsung ke Storage.

## 3. Model data (migration aditif baru, RLS aktif, tanpa policy)

Konten per **brand**. Halaman depan memakai brand dari outlet aktif tunggal atau `/?outlet=<kode>`, sama dengan perilaku sekarang.

### 3.1 `site_media`: pustaka foto

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | uuid PK | |
| `company_id`, `brand_id` | uuid FK | Isolasi tenant |
| `kind` | text check (`hero`, `about`, `gallery`, `logo`, `favicon`) | |
| `category` | text null, check (`interior`, `barista`, `produk`, `suasana`, `komunitas`) | Hanya galeri |
| `path_lg`, `path_sm` | text | Path di bucket; `path_sm` null untuk logo/favicon |
| `width`, `height`, `bytes`, `content_type` | int/int/int/text | Dibaca server dari file (`getimagesizefromstring`, tanpa GD) |
| `alt_text` | text not null, 3–160 karakter | Wajib |
| `caption` | text null, ≤ 160 | Opsional |
| `created_by` | uuid FK users | |
| `created_at`, `updated_at` | timestamptz | |
| `deleted_at` | timestamptz null | Soft delete; file dihapus oleh pembersihan terkendali (§7) |

Index: `(brand_id, kind) where deleted_at is null`.

### 3.2 `site_sections`: konten dengan draft dan versi terbit

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | uuid PK | |
| `company_id`, `brand_id` | uuid FK | |
| `key` | text check (`hero`, `about`, `featured`, `gallery`, `how_to_order`) | Unik per brand |
| `draft` | jsonb not null | Divalidasi server (§5) |
| `published` | jsonb null | Null = belum pernah terbit, landing memakai fallback bawaan |
| `is_visible` | boolean default true | Batal terbit: seksi disembunyikan tanpa menghapus konten |
| `draft_updated_at`, `draft_updated_by` | | |
| `published_at`, `published_by` | | |

Unique `(brand_id, key)`.

Isi JSON (hanya teks polos dan id):
- `hero`: `{ headline, subheadline, ctaLabel, mediaId | null, showImage }`.
- `about`: `{ title, body (paragraf dipisah baris kosong, ≤ 1.200 karakter), mediaIds: [≤ 2] }`.
- `featured`: `{ productIds: [≤ 8] }`. Harga, foto, dan ketersediaan **selalu diambil dari menu** saat dirender. Produk nonaktif, terhapus, atau habis di outlet tidak ditampilkan.
- `gallery`: `{ items: [{ mediaId }] }`, urutan = urutan array, maksimal 24.
- `how_to_order`: `{ steps: [{ title, body }] × 4 }`. Default menyebut "Bayar di kasir" karena hanya tunai yang aktif.

### 3.3 `site_settings`: satu baris per brand, berlaku langsung (dengan konfirmasi)

| Kolom | Catatan |
|---|---|
| `brand_id` unik, `company_id` | |
| `site_name` | Default "Sudaya Coffee" |
| `meta_title` (≤ 60), `meta_description` (≤ 160) | Dirender di Blade (`app.blade.php`) untuk `/`, termasuk Open Graph, agar pratinjau tautan WhatsApp/Instagram bekerja tanpa JavaScript |
| `logo_media_id`, `favicon_media_id` | FK `site_media` |
| `contact_phone`, `contact_whatsapp`, `contact_email` | Validasi format |
| `social` jsonb | `{ instagram, tiktok, facebook }`; host divalidasi (`instagram.com`, `tiktok.com`, `facebook.com`) |
| `maps_url` | Hanya `google.com/maps` atau `maps.app.goo.gl` |
| `updated_by`, `updated_at` | |

Jam buka dan telepon outlet tetap di tabel `outlets` (`operating_hours` dengan format `{ mon: {open, close} \| null, … }`, `phone`), diedit di halaman Outlet & Meja.

## 4. Storage dan pemrosesan gambar

- **Bucket baru `site-media`:**
  - Baca publik, tulis hanya server (service role).
  - Batas 5 MB; tipe `image/jpeg`, `image/png`, `image/webp`.
  - Tanpa SVG (risiko skrip).
  - Bucket `menu-images` tidak dipakai untuk konten situs.
- **Pembuatan bucket:** perintah artisan idempoten `sudaya:storage-setup` (baru), dijalankan sekali per environment dengan persetujuan. Laravel saat ini tidak punya perintah pembuat bucket.
- **Path:** `{companyId}/{brandId}/{kind}/{uuid}-lg.webp` dan `-sm.webp`. Nama file dari pengguna tidak pernah dipakai.
- **Optimasi di browser** (PHP development tidak punya GD/Imagick; jangan bergantung pada ekstensi itu):
  - Foto (hero, about, galeri) di-decode lalu di-encode ulang lewat `canvas` menjadi WebP (fallback JPEG), kualitas ±0,82.
  - Ukuran `lg` (sisi panjang 2.400 px hero, 1.600 px lainnya) dan `sm` (800 px) untuk `srcset`.
  - Re-encode **menghapus metadata EXIF termasuk lokasi GPS** foto HP.
  - Logo dan favicon tidak di-encode ulang (PNG/WebP asli, ≤ 1 MB) agar bentuk logo tidak berubah.
- **Validasi server (tetap wajib walau browser sudah memvalidasi):**
  - Magic bytes (pola `MenuImages::detect`) dan ukuran byte.
  - Dimensi minimal: hero ≥ 1.200 px lebar; galeri/about ≥ 800 px; logo ≥ 256 px; favicon persegi ≥ 192 px.
  - Rasio favicon 1:1.
- **Tampilan:**
  - `<img srcset sizes loading="lazy" decoding="async" width height>`. Hero `fetchpriority="high"`, tanpa lazy.
  - Lebar/tinggi dari database mencegah layout bergeser (CLS).
- **Transformasi gambar Supabase** (render on-the-fly) adalah fitur paket berbayar; tidak diasumsikan (§12).

## 5. Validasi konten

- Semua teks polos, di-trim, dirender sebagai teks (React escape). Tidak ada HTML atau Markdown.
- Panjang:
  - headline 4–80;
  - subheadline ≤ 200;
  - CTA ≤ 32;
  - judul about ≤ 80;
  - body ≤ 1.200;
  - langkah cara pesan: judul ≤ 40, isi ≤ 140.
- **CTA tidak menerima URL bebas.** Tujuan dipilih dari daftar: "Menu outlet" (`/order?outlet=<kode>`) atau seksi di halaman ("Tentang", "Galeri"). Ini mencegah tautan phishing.
- `productIds` harus produk brand yang sama dan aktif saat disimpan. Saat dirender, produk yang kemudian nonaktif atau habis dilewati.
- `mediaId` harus milik brand yang sama, belum dihapus, dan `kind` sesuai seksi.

## 6. Alur draft dan terbit

1. Admin mengubah seksi, lalu **Simpan draft**: hanya `draft` berubah. Halaman publik tidak berubah.
2. **Pratinjau** menampilkan halaman depan dengan semua draft, dalam mode HP dan desktop.
3. **Terbitkan** (per seksi atau "Terbitkan semua perubahan"): `published = draft`, cache halaman depan dibuang, tercatat di audit.
4. **Batalkan perubahan**: `draft = published`.
5. **Sembunyikan seksi** (`is_visible = false`) dan **tampilkan lagi**. Hero tidak bisa disembunyikan; tanpa foto, hero memakai latar espresso.
6. Status per seksi di ringkasan: *Terbit*, *Ada perubahan belum terbit*, *Belum pernah terbit*, *Disembunyikan*.

Pengaturan situs berlaku langsung setelah konfirmasi, karena jarang diubah dan tidak punya pratinjau visual yang berarti. Logo dan meta ikut tampil di pratinjau.

## 7. Hapus dan pembersihan file

- **Hapus foto:**
  - Konfirmasi.
  - Ditolak bila foto dipakai versi terbit, dengan pesan "Lepas foto dari Galeri/Hero lalu terbitkan dulu".
  - Bila hanya dipakai draft, dilepas dari draft.
  - Baris di-soft delete; file belum dihapus.
- **Ganti foto:** foto baru diunggah sebagai media baru; yang lama tetap ada sampai tidak dipakai lalu dihapus. Tidak pernah menimpa file yang sedang dipakai halaman publik.
- **Pembersihan terkendali:** `php artisan sudaya:site-media-prune`.
  - Default `--dry-run`.
  - Menghapus file milik baris yang di-soft delete > 7 hari, dan objek di bucket `site-media` tanpa baris (yatim) > 24 jam.
  - Hanya dengan `--force`, hasil dicatat di audit.
  - Tidak dijadwalkan otomatis sampai disetujui.

## 8. Halaman admin

| Route | Isi |
|---|---|
| `/admin/website` | Ringkasan status seksi, pratinjau, "Terbitkan semua perubahan" |
| `/admin/website/hero` | Form + pratinjau berdampingan (wireframe di UI_UX_SITEMAP §2.6) |
| `/admin/website/about` | Judul, cerita, ≤ 2 foto |
| `/admin/website/featured` | Cari produk, tambah, urutkan (naik/turun), hapus dari unggulan; tampilkan harga dan ketersediaan aktual sebagai info |
| `/admin/website/gallery` | Unggah banyak foto (antrean unggah dengan progres per file), alt text, kategori, urutan, tampil/sembunyi, hapus |
| `/admin/website/settings` | Logo, favicon, nama situs, meta, kontak, sosial, tautan peta |
| `/admin/website/preview` | Halaman depan dengan draft; `noindex`; hanya untuk pengguna berizin |

**Pratinjau dirender sebagai komponen React di dalam halaman admin, tanpa iframe dan tanpa mengubah header keamanan** (keputusan owner K9). Komponen seksi halaman depan dipakai bersama oleh `/` dan editor, dan menerima data draft sebagai props. Agar mode HP dan desktop benar di dalam bingkai pratinjau yang lebih sempit dari layar, seksi landing memakai **container query Tailwind v4** (`@container`, `@sm:`, `@lg:`) alih-alih media query untuk tata letak utamanya. Route `/admin/website/preview` tetap ada sebagai pratinjau halaman penuh di tab baru (`noindex`). Pelonggaran `frame-ancestors` **tidak** dilakukan; bila container query ternyata tidak memadai, alternatif dan risikonya dilaporkan ke owner lebih dulu.

## 9. Render halaman depan

- `HomeController` memuat versi terbit (sections + settings + media) dalam satu deferred prop, seperti `cafe` sekarang, dengan cache `site:published:{brandId}` yang dibuang saat terbit.
- **Fallback:** bila seksi belum pernah terbit, tampilkan copy bawaan saat ini. Bila tidak ada foto, tampilkan latar espresso. Halaman tidak pernah kosong dan tidak pernah menampilkan konten contoh.
- Menu unggulan: id dari CMS, data dari `Storefront` (cache menu yang sudah ada). Tanpa produk unggulan terpilih, perilaku sekarang dipakai (6 produk tersedia, berfoto lebih dulu).
- Meta, Open Graph, dan favicon dirender di Blade dari `site_settings` (server-side).

## 10. Audit

Tercatat lewat `AuditLogger` yang ada (tanpa nilai rahasia):
- `site.media_uploaded`
- `site.media_updated` (alt, kategori)
- `site.media_deleted`
- `site.section_draft_saved` (ringkas: seksi, field yang berubah)
- `site.section_published`
- `site.section_draft_discarded`
- `site.section_visibility_changed`
- `site.settings_updated`
- `site.media_pruned`

## 11. Pengujian (bagian dari UX-2)

- **Unit:** validasi input (panjang, host URL, CTA tujuan), deteksi tipe dan dimensi gambar, peta status seksi.
- **Live (Supabase dev):**
  - isolasi company/brand;
  - izin `settings.manage`;
  - draft tidak tampil publik;
  - terbit membuang cache;
  - hapus ditolak untuk media terbit;
  - produk unggulan nonaktif tidak tampil;
  - harga mengikuti menu;
  - audit tercatat;
  - unggah ke bucket dan bersihkan sendiri di cleanup.
- **E2E:** guest tidak bisa membuka route CMS (401/403); CSRF; unggah file bukan gambar ditolak; pratinjau `noindex`.
- **Browser:**
  - unggah dengan kompresi;
  - pratinjau HP/desktop;
  - terbit lalu halaman depan berubah;
  - tidak ada error console/CSP;
  - tanpa scroll horizontal di 360–1440 px.
- `LiveContext` cleanup ditambah: `site_*` tabel dan objek bucket `site-media` milik company test.

## 12. Keputusan owner sebelum UX-2

| No | Keputusan | Opsi | Rekomendasi |
|---|---|---|---|
| C1 | Siapa yang boleh mengelola website | Super Admin saja (`settings.manage`, tanpa ubah RBAC) / tambah `content.manage` untuk Manager/Owner | **Diputuskan: Super Admin saja (K6)** |
| C2 | Bucket `site-media` baru di dev, nanti staging/production | Ya / pakai `menu-images` | Bucket baru (pemisahan dan batas berbeda) |
| C3 | Cara pratinjau | iframe dengan pelonggaran header / render React | **Diputuskan: render React tanpa melonggarkan CSP (K9)** |
| C4 | Transformasi gambar Supabase berbayar | Tidak / Ya | Tidak; kompres di browser |
| C5 | Pembersihan file otomatis terjadwal | Manual dulu / terjadwal | Manual (`--dry-run`) dulu |
| C6 | Materi: logo resmi (PNG/WebP), foto hero, foto barista/suasana, cerita singkat, tautan sosial, nomor kontak, jam buka, tautan Google Maps | Disediakan owner | Wajib sebelum go-live konten |

## 13. Kriteria terima UX-2

- Super Admin dapat:
  - mengganti foto hero dan headline;
  - mempratinjau di HP dan desktop;
  - menerbitkan, lalu melihat halaman depan berubah tanpa developer.
- Galeri: unggah 10 foto sekaligus, urutkan, sembunyikan 1, hapus 1 (dengan konfirmasi). Halaman publik sesuai.
- Menu unggulan: memilih produk tanpa menyalin harga. Mengubah harga di menu langsung tercermin; produk habis tidak tampil.
- File di bucket memakai nama acak, tanpa EXIF lokasi, tipe dan ukuran tervalidasi di server.
- Semua perubahan tercatat di audit.
- Guest dan role tanpa izin ditolak di server.
- Regression unit, live, E2E, dan browser M1–M4 tetap lolos.
