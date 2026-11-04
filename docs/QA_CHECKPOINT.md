# Checkpoint QA — 4 November 2026, 18.25

Dibuat saat safe shutdown. Pekerjaan berjalan adalah **Tahap 2 setelah persetujuan M4**: pengaturan outlet dan daftar meja resmi. Fitur sudah dibangun dan sebagian besar teruji, tetapi **QA belum selesai dan belum direview owner**. Semua perubahan diamankan di commit WIP lokal (lihat §Git), tidak di-push, dan tidak ada deploy.

## Status M4

M4 **DONE — Development Verified** (disetujui owner 4 November 2026), commit final `370aafc`. Residual QA M4 (R1–R4) tercatat di `GO_LIVE_READINESS.md` dan masih terbuka, lihat di bawah.

## Yang dibangun sejak `370aafc`

| Bagian | File |
|---|---|
| Migration Laravel pertama (aditif): RLS di tabel `migrations`, tabel `outlet_tables` (RLS aktif, unik `(outlet_id, number)`, check format nomor, FK cascade ke outlet) | `database/migrations/2026_11_04_000001_create_outlet_tables.php` |
| Pengaturan outlet: nama, alamat, prefix antrean, batas waktu bayar, status. Butuh `settings.manage` di outlet. Audit `outlet.updated`, cache menu customer dibuang | `app/Services/Outlets/{OutletInput,OutletSettings}.php`, `Admin/OutletController.php`, `pages/admin/outlets/{index,edit}.tsx`, `Storefront::forgetOutlet` |
| Daftar meja: tambah banyak sekaligus (tanpa duplikat), aktif/nonaktif, audit `outlet_table.created` / `outlet_table.status_changed` | sama |
| Checkout QR meja dine in hanya untuk meja terdaftar dan aktif (dicek setelah insert agar retry order lama tetap dikembalikan). POS, kiosk, web, dan takeaway dari QR tidak berubah | `OrderEngine::create`, `OrderController::show` (pesan "QR meja tidak dikenali"), nomor meja read-only di checkout QR |
| Navigasi admin "Outlet & Meja", RouteAccess `/admin/outlets`, `/admin/outlet-tables` → `settings.manage` | `HandleInertiaRequests`, `RouteAccess`, `admin-layout.tsx` |

**Schema:** migration di atas **sudah diterapkan di database development** (batch 1). Setelah diterapkan, 0 tabel `public` tanpa RLS dan 0 policy publik. Catatan: `migrate --pretend` membuat tabel `migrations` tanpa RLS, sehingga di dev tabel itu sempat tanpa RLS sekitar 1 menit (tanpa baris data) sebelum migration dijalankan. Jebakan ini sudah dicatat di `SUPABASE_STAGING_PLAN.md` dan `LARAVEL_STAGING_RUNBOOK.md`.

## Hasil test yang selesai (4 November, sesudah fitur outlet/meja)

| Suite | Hasil | Exit code |
|---|---|---|
| Pint, TypeScript, build Vite | lolos | 0 |
| Unit/feature | 142/142 (512 assertion; 13 baru di `OutletInputTest`) | 0 |
| Live `OutletSettingsTest` (baru) | 6/6 (54 assertion) | 0 |
| Live `OrderEngineTest` | 19/19 | 0 |
| Live `PaymentFlowTest --filter status_pelanggan` (memakai QR meja) | 1/1 | 0 |

Live suite penuh terakhir 107/107 (exit 0, 15.55–16.45) dijalankan **sebelum** fitur outlet/meja.

## Test yang terputus (INCOMPLETE)

E2E penuh (residual R1), dimulai 18.00, dihentikan 18.21 saat shutdown. Log: scratchpad `m1/qa-m4/r1-e2e-full.INCOMPLETE.log`.

| File E2E | Hasil sampai dihentikan |
|---|---|
| `CatalogTest` | 3/4. **1 FAIL**: "alur katalog dari admin sampai menu customer". Dugaan kuat: fixture membuka `/order?outlet=…&table=T03` tanpa mendaftarkan meja T03, sehingga halaman kini menampilkan "QR meja tidak dikenali" sesuai aturan baru. Pesan kegagalan belum tercetak (dicetak di akhir run), jadi **wajib diverifikasi** |
| `OrdersTest` | 6/6 |
| `OutletsTest` (baru) | 2/2 |
| `PaymentFlowTest` | **INCOMPLETE** (sedang berjalan saat dihentikan) |
| `RoutesTest` | **INCOMPLETE** (belum mulai) |

Exit code E2E penuh: tidak ada (dihentikan).

## Belum dijalankan

- QA browser di server multi-worker (residual R2, R3, R4): `m1/probe-m4.mjs` (cek keranjang HP sudah diperbaiki), `m1/probe-outlet.mjs` (baru), `m1/probe-m3.mjs` (regresi setelah validasi meja). Server QA: `node m1/qa-cluster.mjs 8124 6` (proxy + 6 proses `php -S`).
- Pembaruan `LARAVEL_FEATURE_PARITY.md`, `LARAVEL_MIGRATION_PLAN.md`, dan `GO_LIVE_READINESS.md` untuk fitur outlet/meja.

## Defect dan catatan terbuka

| No | Butir | Status |
|---|---|---|
| D1 | E2E `CatalogTest` gagal setelah validasi meja (dugaan: fixture belum mendaftarkan T03) | Terbuka, verifikasi besok |
| D2 | `migrate --pretend` membuat tabel `migrations` tanpa RLS | Ditangani di dev; prosedur staging diperbarui |
| R1–R4 | Residual QA M4 | Terbuka |

## Data dan proses

- Database dev bersih per 18.23: 0 company `test-*`, 0 user `sudaya-test-*`, 0 order/kas/refund/approval/counter/meja/item stok/produk, 0 objek Storage. Company tersisa hanya seed `sudaya`. Fixture E2E yang terputus (runId `afb93ba7`) sudah dibersihkan dengan `fixture.php cleanup`. 4 counter rate limit ter-hash (tidak bisa dipastikan pemiliknya) kedaluwarsa sendiri sebelum 18.28 dan dibersihkan pg_cron.
- Tidak ada proses php, node, atau Chrome headless QA yang berjalan, dan port 8123, 8124, 8140–8145 kosong.

## Langkah pertama besok

1. Baca dokumen ini, `git log --oneline -3`, dan `git status` (harus bersih).
2. Buka `tests/E2E/CatalogTest.php` baris ±102. Bila benar penyebabnya meja T03 tidak terdaftar, daftarkan T03 di fixture (seperti `tests/E2E/PaymentFlowTest.php`). Assertion tidak diubah.
3. Jalankan `php artisan test -c phpunit.live.xml tests/E2E/CatalogTest.php`, lalu **E2E penuh** (`--testsuite E2E`, ±40 menit) untuk menutup R1.
4. QA browser: `node m1/qa-cluster.mjs 8124 6`, lalu `fixture.php create` + `m1/seed-m3.php`, kemudian `probe-m4.mjs` (R2–R4), `probe-outlet.mjs`, dan `probe-m3.mjs`.
5. Perbarui dokumen paritas, rencana migrasi, dan go-live untuk fitur outlet/meja, lalu laporkan ke owner. Commit final fitur baru menunggu persetujuan.
