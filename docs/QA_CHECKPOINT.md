# Checkpoint QA — 5 November 2026, 10.45

Pekerjaan berjalan adalah **Tahap 2 setelah persetujuan M4**: pengaturan outlet, daftar meja resmi, dan redesign tampilan customer (permintaan owner 5 November). Semua test dan QA browser **lolos**. Perubahan 5 November **belum di-commit** dan **belum direview owner**. Tidak di-push, tidak ada deploy.

## Status

| Bagian | Status |
|---|---|
| M4 | **DONE — Development Verified** (disetujui owner 4 November, `370aafc`). Residual R1–R4 **ditutup** 5 November, lihat `GO_LIVE_READINESS.md` |
| Pengaturan outlet + daftar meja | Development Verified. Commit WIP `eff056c`, menunggu review owner |
| Redesign customer | Development Verified. Belum di-commit, menunggu review owner |

## Yang dibangun

Sejak `370aafc` (commit WIP `eff056c`): migration aditif `2026_11_04_000001` (RLS tabel `migrations`, tabel `outlet_tables`), `/admin/outlets`, daftar meja, dan validasi QR meja di checkout. Rinci di `LARAVEL_FEATURE_PARITY.md` §Tahap 2.

Perubahan 5 November (belum di-commit):

| Bagian | File |
|---|---|
| Halaman depan kafe: hero, cara pesan 4 langkah, menu pilihan dan kategori dari data asli, lokasi outlet, tautan staf. Data deferred (`/` tetap tampil tanpa database); `/?outlet=<kode>` untuk outlet tertentu | `app/Http/Controllers/HomeController.php`, `routes/web.php`, `resources/js/pages/home.tsx` |
| Menu customer: judul Fraunces, pencarian menu, chip kategori mengikuti scroll, kartu produk baru. Kiosk tanpa pencarian dan tanpa tautan ke `/` atau `/login` | `resources/js/components/storefront/storefront.tsx` |
| Poles halaman status pesanan | `resources/js/pages/order/status.tsx` |
| Font judul Fraunces (self-hosted, `@fontsource-variable/fraunces` 5.3.0), token `--cream`, smooth scroll + reduced motion | `package.json`, `resources/css/app.css`, `components/brand.tsx` |
| Fixture E2E `CatalogTest` mendaftarkan meja T03 (penyebab D1). Assertion tidak diubah | `tests/E2E/CatalogTest.php` |
| Dokumen | `GO_LIVE_READINESS.md`, `LARAVEL_FEATURE_PARITY.md`, `LARAVEL_MIGRATION_PLAN.md`, dokumen ini |

Token warna PRD tidak diganti (hitam, putih, oranye, hijau). Tidak ada data, testimoni, harga, atau foto karangan.

## Hasil test (5 November, setelah semua perubahan)

| Suite | Hasil | Exit code |
|---|---|---|
| Pint, TypeScript, build Vite | lolos | 0 |
| Unit/feature | 142/142 (512 assertion) | 0 |
| E2E `CatalogTest` | 4/4 | 0 |
| **E2E penuh** (R1) | **35/35** (366 assertion), 09.58–10.38 | 0 |
| Browser redesign (HP, tablet, desktop, kiosk) | 59/59, diulang setelah poles akhir 59/59 | 0 |
| Browser M4 multi-layar (R2, R3, R4) | 73/73 | 0 |
| Browser outlet & meja | 22/22 | 0 |
| Browser M3 regresi | 137/137 | 0 |

Live suite penuh terakhir 107/107 (4 November) dijalankan sebelum fitur outlet/meja. Sesudahnya yang dijalankan adalah Live `OutletSettingsTest` 6/6, `OrderEngineTest` 19/19, dan `PaymentFlowTest --filter status_pelanggan` 1/1. Live penuh perlu diulang sekali sebagai kandidat final bila owner meminta.

Server QA browser: `m1/qa-cluster.mjs` (proxy + 6 proses `php -S`, laptop development). Angka waktu tidak mewakili production.

## Defect dan catatan

| No | Butir | Status |
|---|---|---|
| D1 | E2E `CatalogTest` gagal setelah validasi meja: fixture membuka QR meja T03 yang belum terdaftar | Ditutup (fixture mendaftarkan T03; 4/4 dan E2E penuh 35/35) |
| D2 | `migrate --pretend` membuat tabel `migrations` tanpa RLS | Ditangani di dev; prosedur staging diperbarui |
| D3 | Alat QA: `qa-cluster.mjs` menjalankan `php -S` dari root proyek, padahal router Laravel memakai folder kerja sebagai `public` (semua request 500); worker juga tertinggal bila proxy gagal start | Ditutup (alat QA saja, bukan kode aplikasi) |
| D4 | Alat QA: probe outlet memakai selector `button` untuk Switch yang dirender `role=switch` | Ditutup (selector diperbaiki, assertion sama) |
| R1–R4 | Residual QA M4 | **Ditutup** |
| N1 | Telepon outlet belum ada di form pengaturan outlet | Terbuka, bukan penghalang soft launch |
| N2 | `npx eslint` tidak bisa jalan: proyek belum punya `eslint.config.*` | Terbuka |

## Data dan proses

- Database dev bersih per 10.40: 0 company `test-*`, 0 user `sudaya-test-*`, 0 order/kas/refund/approval/counter/meja/produk/item stok, 0 objek Storage menu. Company tersisa hanya seed `sudaya`. Fixture browser (runId `c43d79c6`) sudah dibersihkan dengan `fixture.php cleanup`, file fixture berisi password test sudah dihapus.
- Tidak ada proses php, proxy QA, atau Chrome headless QA yang berjalan.

## Langkah berikutnya

1. Owner meninjau tampilan baru dan fitur outlet/meja (screenshot di scratchpad QA: `shots-rd2`, `shots-outlet`).
2. Setelah disetujui: commit final Tahap 2 (WIP `eff056c` + perubahan 5 November).
3. Lanjut pekerjaan menuju UAT di `GO_LIVE_READINESS.md` §Pekerjaan minimum (hosting + Supabase staging Singapura, deploy staging, data master asli, UAT outlet). Semua butuh persetujuan owner.
