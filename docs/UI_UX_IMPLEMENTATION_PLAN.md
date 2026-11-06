# Rencana implementasi UI/UX 2.0 (usulan UX-0)

Status: **disetujui secara prinsip oleh owner (6 November 2026), strategi FAST-TRACK SOFT LAUNCH.** Keputusan owner di §0.

## 0. Keputusan owner (6 November 2026)

| No | Keputusan |
|---|---|
| K1 | Design system espresso, krem, oranye, hijau, Fraunces, Geist |
| K2 | Usability, aksesibilitas, dan konsistensi didahulukan dari dekorasi |
| K3 | Target sentuh minimal 44×44 px pada aksi layar sentuh |
| K4 | Bahasa Indonesia yang jelas untuk pelanggan dan staf |
| K5 | Owner mengakses order, sesi kas, dan approval dengan permission yang sudah ada, tanpa izin membuat order |
| K6 | CMS tahap awal hanya untuk Super Admin (`settings.manage`) |
| K7 | CMS: hero, tentang, foto kafe, galeri, menu unggulan dengan harga dari database |
| K8 | Desain Storage foto website disiapkan; tidak membuat resource Supabase staging/production tanpa persetujuan terpisah |
| K9 | Pratinjau CMS memakai render React **tanpa melonggarkan CSP**; bila tidak mungkin, laporkan alternatif dan risiko dulu |
| K10 | Pustaka QR meja boleh setelah pemeriksaan dependency, lisensi, keamanan, dan kebutuhan |
| K11 | Placeholder jelas untuk logo dan foto yang belum ada; tanpa konten bisnis fiktif |

Urutan yang dipilih: **UX-1 → UX-4 → UX-5 → UX-2 → UX-3 + UX-6**, dengan review owner dan commit lokal terpisah setiap milestone yang lolos pengujian.

Dasar:
- [UI_UX_AUDIT.md](UI_UX_AUDIT.md) — masalah dan prioritas.
- [UI_UX_DESIGN_SYSTEM.md](UI_UX_DESIGN_SYSTEM.md) — token dan komponen.
- [UI_UX_SITEMAP.md](UI_UX_SITEMAP.md) — navigasi dan wireframe.
- [ADMIN_CMS_SPEC.md](ADMIN_CMS_SPEC.md) — CMS.

## 1. Aturan kerja setiap milestone

1. Dikerjakan satu milestone per batch, diuji, lalu dilaporkan sebelum lanjut.
2. Tidak mengubah aturan bisnis: harga server, idempotensi, ledger kas, nomor antrean, state machine KDS, approval/refund, inventory guard, audit, isolasi tenant/guest, CSRF.
3. Label yang dipakai test E2E dan probe browser diubah **bersama** test-nya di milestone yang sama, dengan alasan tercatat. Assertion perilaku tidak dilemahkan.
4. QA berbasis risiko:
   - Perubahan UI: typecheck, build, browser terarah.
   - Query atau route baru: unit + live terkait.
   - Migration: validasi migration + live.
   - Full regression hanya di UX-6.
5. Tidak push, deploy, atau membuat resource cloud tanpa persetujuan. Bucket baru di dev (UX-2) juga minta persetujuan dulu.

## 2. Milestone

Estimasi dalam **hari kerja** dengan ritme M1–M4 (fitur + test dalam 1–1,5 hari per modul). Waktu test terhadap Supabase dev dari laptop sudah dihitung: live ±55 menit, E2E ±40 menit. Ini perkiraan kasar, bukan komitmen.

### UX-1: Design system dan layout bersama
**Kompleksitas: M · 1,5–2 hari**

- Token baru:
  - `--espresso`, `--brand-strong`, `--brand-soft`, `--info`;
  - netral hangat;
  - skala ukuran tombol/input 36–64 px;
  - varian `brand` dan `espresso`.
- Komponen baru:
  - `SearchInput`, `Tabs`, `SegmentedControl`, `DropdownMenu`, `Pagination`;
  - `Toolbar`, `DataTable` (tabel + kartu di HP), `StatusBadge` (peta status tunggal);
  - `StatCard`, `Money`, `Breadcrumb`, `FormField` (label + error);
  - `ImageUpload` (dari pola form produk), `ConnectionIndicator`.
- Satu hook mutasi bersama menggantikan 4 hook yang berbeda.
- Shell admin baru:
  - sidebar bergrup, drawer di HP;
  - profil dan Keluar selalu ada;
  - tautan ke Kasir/KDS sesuai izin;
  - breadcrumb.
- Top bar operasional:
  - status kas dan koneksi;
  - tautan Admin sesuai izin;
  - mode ringkas untuk KDS.
- Label Indonesia untuk status dan tipe pesanan, satu sumber di `lib/orders.ts`.

**Kriteria terima:**
- Semua halaman memakai shell baru tanpa perubahan perilaku.
- Tombol bawaan ≥ 40 px; target utama ≥ 44 px.
- Kontras token diukur dan dicatat.
- Tidak ada warna mentah di komponen baru.
- Navigasi menu per role sesuai tabel di sitemap §1.

**Regresi:** pint, tsc, build, unit; probe browser M3/M4/outlet/redesign dengan label baru (diperbarui bersama); tanpa error console/CSP; 360/768/1024/1280 px tanpa scroll horizontal.

**Risiko:** perubahan ukuran tombol global menggeser tata letak semua halaman. Mitigasi: tinjau screenshot setiap halaman sebelum lanjut.

### UX-2: Halaman depan premium dan CMS admin
**Kompleksitas: L · 3–4 hari**

- Migration aditif: `site_media`, `site_sections`, `site_settings` (RLS aktif, tanpa policy).
- Bucket `site-media` dan perintah `sudaya:storage-setup`.
- Service CMS, controller, route, dan audit event.
- Unggah dengan kompresi browser.
- Halaman admin Website (6 halaman) dengan pratinjau.
- Halaman depan baru: hero foto, tentang, menu unggulan, galeri, cara pesan, kunjungi kami, footer; fallback bila CMS kosong.
- Meta/OG server-side.
- Outlet: telepon dan jam buka (kolom yang sudah ada).

**Kriteria terima:** [ADMIN_CMS_SPEC.md](ADMIN_CMS_SPEC.md) §13.

**Regresi:**
- Validasi migration; live CMS baru dan cleanup.
- E2E CMS; unit validasi.
- Probe redesign diperluas.
- `HealthTest` `/` tanpa database tetap lolos.

**Risiko:**
- Perubahan schema pertama setelah M4.
- Header keamanan di route pratinjau.
- Bergantung pada materi foto dari owner. Tanpa foto, halaman depan tetap memakai fallback.

### UX-3: Pemesanan customer dan kiosk
**Kompleksitas: S–M · 1–1,5 hari**

- Tambah langsung untuk produk tanpa modifier.
- Footer "Tambah" sticky.
- Target sentuh 44 px.
- Sembunyikan metode bayar yang belum aktif.
- Label Indonesia.
- Pintasan "pesanan terakhir".
- Status pesanan memakai peta status tunggal.
- Kiosk:
  - panel keranjang samping ≥ 1024 px, tombol 64 px;
  - layar sambut, hitung mundur reset terlihat;
  - kode referensi ≥ 48 px.

**Kriteria terima:**
- Pesanan 2 item tanpa modifier ≤ 5 ketukan di HP, ≤ 8 sentuhan di kiosk.
- Tidak ada data pemesan setelah reset kiosk.
- Realtime status tetap `live`.

**Regresi:** probe M3 dan redesign; E2E `OrdersTest`, `CatalogTest`, `PaymentFlowTest` (alur kiosk/QR).

**Risiko:** rendah (UI). Alur checkout dan idempotensi tidak disentuh.

### UX-4: POS, kas, dan KDS
**Kompleksitas: M · 1,5–2 hari**

- **POS:**
  - tambah langsung tanpa modifier;
  - target 48 px;
  - total dan kembalian 28–36 px;
  - uang cepat dinamis dan input berformat;
  - aksi "Simpan, bayar nanti" dipisah;
  - cetak struk dari dialog hasil;
  - status kas dan koneksi di top bar.
- **Detail pesanan:**
  - hasil bayar di dialog besar;
  - approval dengan konfirmasi dan waktu pengajuan;
  - peringatan refund saat kas tertutup;
  - label riwayat lengkap.
- **Kas:**
  - kartu angka besar;
  - tutup kas dua langkah (hitung fisik dulu);
  - label lebih/kurang;
  - petunjuk minimal alasan;
  - nama penutup di riwayat (props).
- **KDS:**
  - 3 kolom di ≥ 1024 px, strip Selesai;
  - nomor 40–48 px; jumlah tebal di depan item; modifier kontras;
  - label Indonesia, label "Terlambat", badge sumber;
  - timer per tahap (props yang sudah dikirim);
  - bunyi opsional, banner koneksi, mode layar penuh.
- **Struk:** alamat dan telepon outlet.

**Kriteria terima:**
- 2 item tanpa modifier + bayar pas ≤ 5 ketukan.
- Kembalian terbaca dari 1 m.
- KDS 3 kolom tanpa scroll di 1024 × 768; nomor terbaca dari 2 m.
- Struk tetap 1 halaman 80 mm.

**Regresi:** live `PaymentFlowTest` (kas, bayar, refund, konkurensi tidak berubah); E2E `PaymentFlowTest`; probe M4 multi-layar di server QA 6 worker.

**Risiko:**
- Paling dekat ke uang tunai. Hanya tampilan yang berubah; validasi uang tetap di server.
- Tutup kas dua langkah mengubah urutan layar, bukan aturan selisih.

### UX-5: Dashboard, admin, dan pengalaman Manager/Owner
**Kompleksitas: M–L · 2–3 hari**

- **Dashboard** dari data asli (query agregat hanya-baca, scope outlet + tenant):
  - penjualan lunas hari ini, transaksi, rata-rata, item terjual;
  - menunggu bayar, selesai;
  - sesi kas dan selisih terakhir;
  - approval menunggu, produk habis;
  - terlaris hari ini;
  - empty state bila belum ada transaksi.
  - Pemilih outlet bila > 1 outlet.
- **`/admin/orders`:** hanya-baca + kotak masuk approval, memakai izin `order.view` / `approval.*` yang sudah ada. Owner bisa melihat pesanan dan menyetujui pembatalan tanpa akses kasir.
- **Daftar admin** memakai `Toolbar` + `DataTable` + `Pagination`:
  - produk: filter langsung, slug ke pengaturan lanjutan;
  - pengguna: konfirmasi nonaktif/cabut/reset; undangan tanpa role default; sheet;
  - kategori dan modifier: satu pola sheet/dialog;
  - role: label Indonesia, header sticky;
  - audit: label, filter, paginasi server.
- **Outlet:** tab meja & QR (unduh/cetak QR, butuh persetujuan satu pustaka QR kecil), jam buka, kontak.
- **Beranda staf:** hanya fitur yang ada, ringkasan kas dan order menunggu.

**Kriteria terima:**
- Angka dashboard sama dengan hitungan test live.
- Owner dapat menyetujui pembatalan tetapi tidak bisa membuat order atau menerima uang.
- Semua aksi akses dikonfirmasi.
- Tidak ada kode mentah yang tampil ke staf non-teknis.

**Regresi:** live baru (dashboard, admin orders, audit filter) + `AccessControlTest`, `CatalogTest`, `OutletSettingsTest`; E2E `RoutesTest`, `OutletsTest`, `CatalogTest`; probe M2/outlet.

**Risiko:**
- Route dan query baru menyentuh otorisasi. Hanya izin yang sudah ada yang dipakai; perubahan RBAC tidak termasuk.
- Pustaka QR adalah dependency baru (persetujuan).

### UX-6: QA akhir
**Kompleksitas: M · 1–1,5 hari**

- Audit aksesibilitas (kontras terukur, keyboard, label, screen reader dasar) dan responsif di 6 ukuran perangkat.
- Performa diukur dari staging bila sudah ada: LCP, CLS, INP, ukuran bundle, ukuran gambar. Tanpa staging, dicatat sebagai belum terukur.
- Full regression: unit, live, E2E, browser semua probe, kandidat commit final.

**Kriteria terima:** semua suite exit 0; checklist [UI_UX_DESIGN_SYSTEM.md](UI_UX_DESIGN_SYSTEM.md) §6 untuk semua halaman; tidak ada klaim performa production dari laptop.

**Total estimasi:** ±10–14 hari kerja untuk seluruh program.

## 3. Risiko terhadap soft launch

| No | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| S1 | Program penuh (±2–3 minggu) lebih panjang dari jadwal soft launch | Soft launch tertunda | Jalur cepat §4; CMS bisa menyusul setelah soft launch |
| S2 | Perubahan UI global (ukuran tombol, label) menggeser banyak layar sekaligus | Regresi visual | Batch kecil, screenshot per halaman, probe dengan label baru |
| S3 | Staf sudah berlatih dengan layar lama (UAT) | Kebingungan saat soft launch | Selesaikan POS/KDS sebelum UAT, bukan sesudahnya; panduan singkat 1 halaman |
| S4 | Materi foto dan logo belum ada | Halaman depan tetap tanpa foto | CMS memungkinkan unggah kapan saja; fallback tanpa foto tetap rapi |
| S5 | Migration CMS menambah langkah staging | Staging lebih panjang | Migration aditif; masuk checklist [STAGING_PREFLIGHT.md](STAGING_PREFLIGHT.md) §2 |
| S6 | Label baru memecah test | Waktu QA bertambah | Diperbarui di milestone yang sama, tercatat |
| S7 | Header keamanan untuk pratinjau CMS | Kesalahan konfigurasi membuka framing | Hanya route pratinjau berizin, diuji E2E |

## 4. Rekomendasi urutan

**Jalur cepat sebelum soft launch (±5–6 hari)**, berurutan:

1. **UX-1**, porsi inti: token, ukuran kontrol, shell admin dan top bar, peta status, label Indonesia.
2. **UX-4**: POS, kas, dan KDS (dampak terbesar pada kecepatan transaksi dan salah operasi).
3. **UX-5**, porsi P0: dashboard data asli, `/admin/orders` + approval untuk Owner, konfirmasi aksi pengguna.
4. Regresi terarah + probe multi-layar.

**Setelah atau paralel dengan staging:**

5. **UX-2**: CMS + halaman depan premium, segera setelah owner menyediakan materi (C6).
6. **UX-3**: customer dan kiosk (sudah diredesign 5 November; sisanya penyempurnaan).
7. Sisa **UX-5**: admin list, audit, role, QR meja.
8. **UX-6**: QA akhir.

Alasan: halaman customer sudah layak sejak redesign 5 November, sedangkan layar operasional dan akses Owner adalah titik risiko terbesar saat outlet mulai melayani.

## 5. Keputusan yang dibutuhkan dari owner

| No | Keputusan | Rekomendasi |
|---|---|---|
| D1 | Setujui UX-0 (5 dokumen ini) dan arah visual | — |
| D2 | Urutan: jalur cepat §4 atau program penuh berurutan UX-1 → UX-6 | Jalur cepat |
| D3 | Owner akan memberi approval pembatalan saat soft launch? (menentukan `/admin/orders` P0 atau P1) | Ya, P0 |
| D4 | Keputusan CMS C1–C6 ([ADMIN_CMS_SPEC.md](ADMIN_CMS_SPEC.md) §12) | Lihat dokumen |
| D5 | Dependency baru: pustaka QR kecil untuk unduh QR meja (UX-5) | Ya, versi tetap, tanpa layanan luar |
| D6 | Bunyi notifikasi order baru di KDS (butuh interaksi barista sekali per sesi) | Ya, opsional |
| D7 | Penyediaan materi: logo resmi, foto hero/barista/suasana/produk, cerita singkat, kontak, jam buka, sosial, tautan Maps | Sebelum UX-2 |
