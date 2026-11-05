# Checkpoint QA — 5 November 2026, 14.10

Tahap 2 setelah persetujuan M4 (pengaturan outlet, daftar meja resmi, redesign tampilan customer) **selesai dan disetujui owner**. Commit final lokal **`fb01dfd`** adalah kandidat staging dan lolos seluruh regresi, termasuk Live suite penuh M1–M4. Tidak di-push, tidak ada remote, tidak ada deploy. Ini bukan pernyataan siap production.

## Status

| Bagian | Status |
|---|---|
| M4 | **DONE — Development Verified** (disetujui owner 4 November, `370aafc`) |
| Residual QA M4 R1–R4 | **CLOSED** (5 November), rincian di `GO_LIVE_READINESS.md` §Residual |
| Pengaturan outlet + daftar meja | **DONE — Development Verified**, disetujui owner 5 November (`eff056c` + `fb01dfd`) |
| Redesign customer (halaman depan, menu, kiosk, status) | **DONE — Development Verified**, disetujui owner 5 November (`fb01dfd`) |
| Kandidat staging | `fb01dfd`, lolos regresi penuh. Langkah staging di `STAGING_PREFLIGHT.md` |

## Yang dibangun sejak M4 final `370aafc`

| Commit | Isi |
|---|---|
| `eff056c` (WIP) | Migration aditif `2026_11_04_000001` (RLS tabel `migrations`, tabel `outlet_tables`), `/admin/outlets`, daftar meja, validasi QR meja di checkout |
| `fb01dfd` (final) | Halaman depan kafe (`HomeController`, data deferred, `/?outlet=<kode>`), menu customer (pencarian, chip kategori mengikuti scroll, kartu produk baru), kiosk tanpa pencarian dan tanpa tautan ke `/` atau `/login`, poles status pesanan, font Fraunces self-hosted, token `--cream`, reduced motion, fixture E2E `CatalogTest` mendaftarkan meja T03 (assertion tidak diubah), dokumen |

Total sejak `370aafc`: 35 file, +1.771 / −112. Token warna PRD tidak diganti. Tidak ada data, testimoni, harga, atau foto karangan.

## Hasil test kandidat `fb01dfd`

| Suite | Hasil | Exit code | Kapan |
|---|---|---|---|
| Pint, TypeScript, build Vite | lolos | 0 | 5 November, sebelum commit |
| Unit/feature | 142/142 (512 assertion) | 0 | 5 November, sebelum commit |
| E2E penuh (R1) | 35/35 (366 assertion) | 0 | 5 November 09.58–10.38 |
| Browser redesign (HP, tablet, desktop, kiosk) | 59/59 | 0 | 5 November |
| Browser M4 multi-layar (R2, R3, R4) | 73/73 | 0 | 5 November |
| Browser outlet & meja | 22/22 | 0 | 5 November |
| Browser M3 regresi | 137/137 | 0 | 5 November |
| **Live suite penuh M1–M4** | **113/113 PASS (691 assertion)** | **0** | 5 November 13.11–14.04, setelah commit `fb01dfd` |

Unit, E2E, dan browser QA **tidak diulang** setelah commit: source code di `fb01dfd` sama dengan yang diuji, sehingga hasil di atas tetap berlaku. Live suite penuh dijalankan satu kali pada commit kandidat. Rinciannya: `AccessControlTest`, `BootstrapTest`, `CatalogTest`, `LoginSecurityTest`, `OrderEngineTest`, `OutletSettingsTest`, `PaymentFlowTest`, `SeedTest`, `SupabaseSessionTest`. Log tersimpan di scratchpad QA (`m1/qa-m4/live-full-staging-candidate.log`).

Server QA browser: `m1/qa-cluster.mjs` (proxy + 6 proses `php -S`, laptop development). Semua angka waktu berasal dari laptop development dan tidak mewakili production.

## Defect dan catatan

| No | Butir | Status |
|---|---|---|
| R1 | E2E regresi penuh | **CLOSED**: 35/35 |
| R2 | Browser M4 di server multi-worker | **CLOSED**: 73/73 di server QA 6 worker. Staging tetap wajib diuji ulang dengan PHP-FPM |
| R3 | Cek keranjang mobile | **CLOSED**: probe membuka sheet keranjang di HP; cek lolos di run 73/73 |
| R4 | POS → bayar → KDS → Completed dalam satu run | **CLOSED**: satu run 73/73 sampai refund dan tutup kas |
| D1 | E2E `CatalogTest` gagal setelah validasi meja: fixture membuka QR meja T03 yang belum terdaftar | Ditutup (fixture mendaftarkan T03) |
| D2 | `migrate --pretend` membuat tabel `migrations` tanpa RLS | Ditangani di dev; prosedur staging memakai pembacaan file, bukan `--pretend` |
| D3 | Alat QA: `qa-cluster.mjs` menjalankan `php -S` dari root proyek (semua request 500) dan meninggalkan worker bila proxy gagal start | Ditutup (alat QA saja) |
| D4 | Alat QA: probe outlet memakai selector `button` untuk Switch yang dirender `role=switch` | Ditutup (selector diperbaiki, assertion sama) |
| N1 | Telepon outlet belum ada di form pengaturan outlet | Terbuka, bukan penghalang soft launch |
| N2 | `npx eslint` tidak bisa jalan: proyek belum punya `eslint.config.*` | Terbuka |
| S1 | Temuan staging preflight: `trustProxies` belum ada, penjaga `LiveTestCase` tidak selalu efektif, default sesi/cache `database` | Terbuka, ditangani lewat konfigurasi dan prosedur rilis di `STAGING_PREFLIGHT.md`; perubahan kode hanya dengan persetujuan |

## Data dan proses

- Database dev bersih setelah Live suite (5 November, 14.05): 0 company `test-*`, 0 user `sudaya-test-*`, 0 order/pembayaran/kas/refund/approval/counter/meja/produk/kategori/item stok, 0 objek Storage menu. Company tersisa hanya seed `sudaya` dengan 1 outlet.
- Fixture browser (runId `c43d79c6`) dibersihkan dengan `fixture.php cleanup`; file fixture berisi password test sudah dihapus.
- Tidak ada proses php, proxy QA, atau Chrome headless QA Sudaya yang berjalan.

## Langkah berikutnya

Semua butuh persetujuan owner. Urutan dan cara verifikasi ada di `STAGING_PREFLIGHT.md` §5 dan §10:

1. Pilih hosting Singapura, buat remote Git privat, buat project Supabase staging Singapura, siapkan subdomain.
2. Uji kelayakan server, lalu tentukan apakah perlu `trustProxies`.
3. Migration terkontrol, deploy staging, smoke test.
4. Data master asli dan UAT outlet.
