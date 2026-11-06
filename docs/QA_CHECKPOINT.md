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

## UX-4 — POS, sesi kas, KDS (6 November 2026, selesai 18.10)

**Status: Development Verified, menunggu review owner.** Commit lokal UX-4 dibuat setelah QA lulus; **tidak di-push**. Tidak ada perubahan PHP, migration, aturan bisnis, harga, idempotensi, ledger kas, refund, inventory guard, RBAC, CSP, atau CSRF.

| Bagian | Sebelum | Sesudah | File |
|---|---|---|---|
| POS | Produk tanpa varian tetap membuka dialog; tombol jumlah 28 px; kembalian `text-sm`; uang cepat hanya Pas/50rb/100rb; input tanpa pemisah ribuan; "Simpan, bayar nanti" menempel tombol bayar; error tampil dua kali | Tambah langsung (1 ketukan) + catatan per baris dari keranjang; tipe pesanan selalu terlihat; tombol jumlah 40/44 px; total 24 px; uang diterima berformat ribuan + saran nominal (pembulatan 5rb/10rb/20rb/50rb/100rb); "Kembalian"/"Uang kurang" 24–30 px; tombol bayar 52 px; status kas di bar alat; dialog hasil (nomor 60 px, kembalian 36 px, cetak struk langsung `?print=1`); error tampil sekali | `pages/pos/index.tsx`, `lib/money.ts`, `components/money-input.tsx`, `components/catalog/product-options.tsx`, `pages/pos/orders/receipt.tsx` |
| Sesi kas | "Saldo seharusnya" `text-base`; selisih bertanda minus tanpa label; minimal alasan tidak diberitahukan; tombol 32 px berdempetan | Saldo sistem 30 px di panel espresso; dialog tutup: Saldo sistem / Uang fisik / Selisih ("Cocok", "Uang lebih Rp …", "Uang kurang Rp …"); petunjuk minimal 3 karakter; batas kas keluar = saldo sistem; masuk/keluar berwarna dan berikon; "Tutup sesi" dipisah | `pages/pos/cash.tsx` |
| KDS | 2 kolom di 1024 px (Siap/Selesai di bawah lipatan); nomor 24 px; modifier 14 px abu; label Inggris; indikator koneksi kecil | 3 kolom kerja di ≥ 1024 px + strip Selesai; tab per status di layar kecil; nomor 36 px; "2×" tebal di depan menu; varian 16 px; catatan disorot; sumber pesanan; "Terlambat"/"Perhatikan"; timer tahap; status koneksi + waktu pembaruan + banner terputus/offline; layar penuh. Realtime, polling cadangan 5 detik, dan penyambungan ulang tetap | `pages/kds/index.tsx`, `components/orders/live-refresh.tsx` |
| Detail pesanan | Aksi turun ke bawah di HP; kembalian hanya di toast; Setujui/Tolak 28 px tanpa konfirmasi; refund tanpa peringatan kas | Nomor + status di judul; kartu Pembayaran paling atas di HP; konfirmasi bayar berformat + dialog hasil besar; konfirmasi "Setujui pembatalan" (Tolak tanpa konfirmasi); peringatan refund bila kas belum dibuka; waktu pengajuan tampil | `pages/pos/orders/show.tsx`, `components/orders/order-actions.tsx` |
| Daftar pesanan | Filter 32 px, cari h-9 Enter saja | Filter dan cari 40/44 px, tombol Cari | `pages/pos/orders/index.tsx` |
| Umum | Label pembaca layar "Close" | "Tutup"; tautan brand POS ≥ 36/44 px | `components/ui/sheet.tsx`, `dialog.tsx`, `layouts/pos-layout.tsx` |

### Hasil QA akhir (Supabase dev eu-west-1, `APP_ENV=local`, server QA lokal 6 worker)

| Suite | Hasil | Exit | Catatan |
|---|---|---|---|
| Pint, TypeScript, build | lolos | 0 | build final 17.48 |
| Unit/feature | 142/142 (512 assertion) | 0 | tidak ada perubahan PHP |
| E2E `PaymentFlowTest` | 5/5 (103 assertion) | 0 | 17.33–17.48 |
| Browser `probe-ux4` (baru) | **56/56** | 0 | POS desktop/tablet/HP (sentuh ≥ 44 px), KDS 1024 px + HP (tab), tutup kas (kurang/lebih/cocok), detail HP, dialog hasil |
| Browser `probe-m4` multi-layar | **74/74** | 0 | kasir, KDS, pelanggan, manager; batal → approval (konfirmasi baru) → refund → tutup kas; realtime `live` |
| Browser `probe-m3` | **140/140** | 0 | menu, checkout, kiosk, POS (cek baru tambah langsung) |
| Browser `probe-redesign` | 59/59 | 0 | dijalankan sebelum perbaikan tata letak keranjang terakhir; halaman customer tidak berubah sesudahnya |

Probe UX-4, M4, dan M3 di atas dijalankan ulang pada build final, setelah perbaikan D6.

### Defect dan perbaikan

| No | Temuan | Jenis | Perbaikan |
|---|---|---|---|
| D5 | `probe-ux4` gagal: `maxTouchPoints: 0` ditolak Chrome | Bug probe | `{ enabled: false }` |
| D6 | POS laptop 800 px dan HP: area bayar terlalu tinggi, pilihan tipe pesanan terdesak ke area gulir (terlihat di screenshot) | **Bug aplikasi (UX)** | Tipe pesanan dipindah ke area tetap di bawah judul keranjang; input uang + nominal cepat satu baris; kotak kembalian ringkas; pemisah "atau" dihapus. Diuji ulang UX-4/M4/M3 |
| D7 | `probe-ux4` gagal mencari produk di POS tablet: `page.go()` kembali sebelum halaman baru menggantikan halaman lama (race) | Bug probe | Tunggu tombol produk ada sebelum klik |
| D8 | Push gagal sore hari (`github.com:443` tidak terjangkau) | Jaringan | Push UX-0/UX-1 berhasil malam ini (`107ddb6` di GitHub) |

### Data, proses, Git

- Database dev bersih (18.10): 0 company `test-*`, 0 user `sudaya-test-*`, 0 order/pembayaran/kas/refund/approval/counter/meja/produk/kategori, 0 objek Storage menu; tersisa company seed `sudaya`. Fixture `24a741be`, `40fbd1d4`, `3673e526`, `774bba4c`, `803875e3`, `28d74c11` dibersihkan; file fixture dihapus.
- Tidak ada proses QA yang berjalan.
- GitHub `main` = `107ddb6` (UX-0 + UX-1). Commit UX-4 hanya lokal.

### Risiko tersisa

- POS di HP tetap padat (keranjang di sheet); perangkat utama kasir sebaiknya tablet atau laptop.
- Bunyi notifikasi pesanan baru di KDS belum ada (keputusan D6 rencana UX-0 belum diambil).
- Probe berjalan di server QA `php -S` dari laptop; latensi dan perilaku PHP-FPM tetap harus diuji di staging.
- Printer thermal fisik belum diuji; `?print=1` hanya membuka dialog cetak browser.
