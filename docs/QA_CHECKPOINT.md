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

## UX-5 — Dasbor Owner, area pemantauan, ekspor menu (8–9 November 2026)

**Status: Development Verified, menunggu review owner.** Commit lokal dibuat setelah QA lulus; **tidak di-push**. Keputusan owner 8 November: UX-5 dulu lalu absensi (GPS + selfie), data produk diinput dari web + ekspor spreadsheet. Rencana dan status: `UX5_GOLIVE_PLAN.md`.

Tidak ada migration, perubahan RBAC (izin per peran), aturan pembayaran/refund/kas/inventory/checkout, harga, CSP, atau CSRF. Peta rute bertambah dua prefix untuk halaman baru: `/admin/approvals` → `approval.view`, `/admin/cash` → `cash.view` (`/admin/orders` → `order.view` sudah ada).

| Bagian | Isi | File utama |
|---|---|---|
| Dasbor `/admin` | Angka asli outlet aktif, tanggal operasional zona waktu outlet: omzet bersih (pesanan lunas, tanpa batal/refund), transaksi, rata-rata, item terjual, 5 menu terlaris (batang satu warna + angka tertulis), pesanan menunggu bayar/antrean/selesai, kas (saldo sistem sesi terbuka, selisih sesi terakhir dengan ikon + label), "Perlu tindakan". Data dimuat sesudah halaman tampil (deferred + skeleton), diperbarui tiap 60 detik (jeda saat tab tidak aktif) + tombol Perbarui. Tiap bagian hanya dihitung bila peran punya izinnya. Pemilih outlet bila > 1 | `Services/Dashboard/DashboardSummary.php`, `Admin/DashboardController.php`, `pages/admin/dashboard.tsx` |
| Area Owner | `/admin/orders` (+ detail, struk), `/admin/approvals` (kotak persetujuan pembatalan; setujui dengan konfirmasi, tolak), `/admin/cash` (hanya-lihat). Halaman kasir dipakai ulang dengan prop `area`; di area admin tombol bayar/batal/refund dan buka/tutup laci tidak ditampilkan (endpoint aksi tetap di `/pos`, dijaga `order.create`). Menu sidebar: Pesanan hari ini, Persetujuan, Kas untuk pengguna tanpa akses kasir; Persetujuan untuk semua yang berizin | `Admin/AdminOrderController.php`, `Admin/AdminCashController.php`, `Admin/ApprovalController.php`, `OrderEngine::pendingApprovals`, `layouts/area-layout.tsx`, `lib/area.ts`, `pages/admin/approvals.tsx`, `pages/pos/orders/*`, `pages/pos/cash.tsx`, `components/orders/order-actions.tsx`, `layouts/admin-layout.tsx` |
| Ekspor menu | Tombol **Ekspor ke spreadsheet** → `.xlsx` (kategori, nama, SKU, deskripsi, harga dasar, status, varian & tambahan, ketersediaan + harga khusus per outlet). Izin `product.view`, brand milik company sendiri; teks disimpan sebagai teks (tidak pernah jadi rumus), karakter kontrol dibuang, `Cache-Control: no-store`. Tanpa dependency baru (ZipArchive) | `Support/Spreadsheet/XlsxWriter.php`, `Services/Catalog/MenuExport.php`, `ProductController::export` |
| Daftar admin | Menu: filter langsung (pilihan berubah = diterapkan, cari setelah berhenti mengetik), tombol Terapkan dihapus, Hapus filter. Staf: cari nama/email. Log aktivitas: nama aksi bahasa Indonesia (kode asli tetap tampil kecil) + filter kategori | `pages/admin/products/index.tsx`, `pages/admin/users.tsx`, `pages/admin/audit.tsx` |

### Hasil QA (Supabase dev eu-west-1, `APP_ENV=local`, server QA lokal 6 worker)

| Suite | Hasil | Exit | Catatan |
|---|---|---|---|
| Pint, TypeScript, build | lolos | 0 | |
| Unit/feature | 147/147 (544 assertion) | 0 | termasuk 5 test baru `XlsxWriterTest` |
| Live `DashboardTest` (baru) | 5/5 (72 assertion) | 0 | angka penjualan/pesanan/kas/persetujuan dari transaksi nyata, izin per bagian, tenant lain tidak terhitung, ekspor xlsx (teks rumus tetap teks, tanpa izin ditolak). Assertion brand tenant lain → `not_found` ditambahkan sesudahnya dan ikut Live suite penuh M7 |
| E2E `AdminMonitoringTest` (baru) | 4/4 (58 assertion) | 0 | deferred `summary` lewat partial reload, Owner `/pos` 403, area admin (flag aksi kasir mati), setujui pembatalan via `/admin/approvals`, keputusan ganda ditolak, ekspor HTTP (header, `PK`), barista/guest/tenant lain ditolak |
| Browser `probe-ux5` (baru) | **49/49** | 0 | dasbor desktop/tablet/HP, Perbarui, persetujuan dari dasbor → setujui (omzet turun 69.000 → 42.000), pesanan & kas admin hanya-lihat, ekspor + filter langsung Menu, regresi kasir |
| Browser `probe-ux5b` (baru) | **15/15** | 0 | grid angka 2 kolom di HP (angka tidak terpotong, kontrol ≥ 44 px), Log aktivitas (label + filter), cari Staf |
| Regresi `probe-ux4` | **56/56** | 0 | POS, kas, KDS, detail pesanan |
| Regresi `probe` (M1 admin) | **76/76** | 0 | login, staf, peran, log, 403, HP/tablet |
| Regresi `probe-m4` | **74/74** | 0 | diulang dengan fixture baru (D10): kasir, KDS, pelanggan, manager; batal → approval → refund → tutup kas; realtime `live` |

### Defect dan perbaikan

| No | Temuan | Jenis | Perbaikan |
|---|---|---|---|
| D9 | Dasbor HP: kartu angka bertumpuk satu per baris, layar penuh hanya untuk 2 angka (terlihat di screenshot) | UX | 2 kolom di HP/tablet, omzet selebar layar; diuji `probe-ux5b` |
| D10 | `probe-m4` pertama berhenti: simpan pesanan POS melewati batas 30 detik PHP saat menunggu database dev (log `Maximum execution time … Connection.php`) | Lingkungan (latensi DB dev eu-west-1) | Jalur POS/KDS tidak diubah UX-5; probe M4 diulang dengan fixture baru |
| D11 | Probe sendiri: pengecekan tombol filter Log memakai ekspresi yang salah | Bug probe | Dicek per label |

### Data, proses, Git

- Database dev bersih setelah QA: 0 company `test-*`, 0 user `sudaya-test-*`, 0 order/pembayaran/kas/approval/meja/produk/kategori; tersisa company seed `sudaya`. Fixture browser dibersihkan dengan `fixture.php cleanup`; file fixture berisi password test dihapus.
- Commit lokal UX-5 dibuat setelah QA; **belum di-push** (menunggu persetujuan owner). GitHub `main` tetap `df80e00`.

### Risiko tersisa

- Angka dasbor = tanggal operasional (zona waktu outlet) berdasarkan `business_date` saat bayar; daftar Pesanan hari ini memakai tanggal dibuat. Pesanan yang dibuat sebelum tengah malam dan dibayar sesudahnya tampil di dua hari berbeda (perilaku lama, didokumentasikan).
- Pembaruan otomatis dasbor 60 detik (polling); pesanan dan persetujuan memakai realtime.
- Live suite penuh dijalankan sekali bersama M7-lite (absensi) agar database dev tidak dipakai bersamaan.

## M7-lite — Karyawan & absensi GPS + selfie, QR meja (9 November 2026)

**Status: Development Verified, menunggu review owner.** Keputusan owner: opsi A GPS + foto selfie (8 November), migration enum disetujui (G6), foto disimpan 90 hari (G5), radius awal 30 m dan akurasi maksimal 30 m (G4) — 9 November. Commit lokal; **belum di-push**.

Diport dari referensi Next.js Epic 05 (geofence, koreksi, rekap), dengan verifikasi wajah diganti foto selfie di bucket privat. Tidak ada perubahan RBAC (izin per peran), pembayaran, kas, refund, inventory, checkout, harga, CSP, atau CSRF. Peta rute tidak berubah (prefix `/admin/attendance*`, `/admin/employees`, `/staff/attendance` sudah ada sejak awal).

| Bagian | Isi | File utama |
|---|---|---|
| Migration | `2026_11_09_000001`: nilai `gps_selfie` pada enum `attendance_method` (aditif, di luar transaksi). Dijalankan di dev 9 November | `database/migrations/` |
| Absen staf `/staff/attendance` | Tombol besar Absen masuk/pulang → kamera depan (selfie diperkecil di HP ke ±960 px) → lokasi dicek otomatis (pratinjau jarak & akurasi dari server) → Kirim. Posisi diambil ulang bila > 60 detik. Status kerja + timer, absen hari ini, peringatan sesi kemarin belum pulang, catatan privasi | `pages/staff/attendance.tsx`, `lib/attendance.ts`, `Staff/AttendanceController.php` |
| Aturan server | Haversine; tolak posisi > 2 menit, akurasi > batas (default 30 m), di luar radius outlet (default 30 m), outlet tanpa koordinat; penolakan dicatat `attendance.location_rejected`. Satu sesi terbuka per karyawan (unique index), absen pulang kondisional, jam kerja dihitung DB. Foto wajib, dicek dari isi file (JPG/PNG/WebP ≤ 3 MB), disimpan `selfie/<company>/<karyawan>/<bulan>/<uuid>` di bucket privat `attendance-evidence`; gagal simpan → foto dihapus | `Services/Attendance/{AttendanceEngine,Geofence,AttendanceInput,AttendancePhotos}.php` |
| Koreksi | Staf: lupa absen (masuk+pulang) atau lupa pulang, alasan wajib, tidak boleh masa depan / > 24 jam / ganda. Owner/Manager memutuskan di **Persetujuan** (setujui dengan konfirmasi); disetujui → sesi `manual_correction`, cek tumpang tindih; pemohon tidak bisa memutuskan sendiri | `AttendanceEngine::{requestCorrection,decideCorrection}`, `pages/staff/attendance-history.tsx`, `pages/admin/approvals.tsx`, `ApprovalController::decide` |
| Admin | **Kehadiran** (papan harian: bekerja/sudah pulang/belum absen, jam, jarak, foto masuk/pulang lewat route berizin `Cache-Control: private, no-store`), **Rekap absensi** + ekspor `.xlsx`, **Data karyawan** (tambah/ubah/nonaktifkan, tautan ke akun staf; tidak bisa dinonaktifkan saat masih tercatat masuk), **Pengaturan absen** (titik dari lokasi perangkat atau ketik, radius 5–1.000 m, batas akurasi company) | `Services/Attendance/{AttendanceAdmin,Employees}.php`, `Admin/{AttendanceController,EmployeeController}.php`, `pages/admin/attendance/*`, `pages/admin/employees/*` |
| Retensi | `sudaya:purge-attendance-photos` harian 03.15 WIB: foto > 90 hari dihapus dari Storage lalu path dikosongkan; jam, lokasi, metode tetap. Server butuh cron `schedule:run` (dokumen deploy diperbarui) | `Console/Commands/PurgeAttendancePhotosCommand.php`, `routes/console.php` |
| Navigasi & dasbor | Grup sidebar **Karyawan**; **Absen saya** untuk manager; pintasan **Absen** di beranda staf dan header kasir; dasbor: kartu **Kehadiran hari ini** + "koreksi absen menunggu persetujuan" | `layouts/*`, `pages/staff/home.tsx`, `DashboardSummary.php`, `pages/admin/dashboard.tsx` |
| QR meja | **Outlet & meja → Cetak QR meja**: lembar A4 2 kolom, satu kartu per meja aktif (nama outlet, nomor, QR SVG hitam-putih, petunjuk). QR dibuat di browser dengan `qrcode-generator` 2.0.4 (MIT, tanpa dependency), tanpa layanan pihak ketiga | `pages/admin/outlets/qr.tsx`, `OutletController::qr` |
| Panduan | Panduan pemakaian untuk klien (Owner, Super Admin, Kasir, Barista, staf absen) | `docs/PANDUAN_PENGGUNA.md` |

### Hasil QA (Supabase dev eu-west-1, `APP_ENV=local`, server QA lokal 6 worker)

| Suite | Hasil | Exit | Catatan |
|---|---|---|---|
| Pint, TypeScript, build | lolos | 0 | halaman QR jadi chunk terpisah 23,5 kB (8,6 kB gzip) |
| Unit/feature | 152/152 (561 assertion) | 0 | termasuk 5 test baru `GeofenceTest` (Haversine, radius, akurasi, posisi lama, tugas luar, outlet tanpa koordinat) |
| Live `AttendanceTest` (baru) | **7/7 (143 assertion)** | 0 | absen masuk/pulang + foto + audit, ganda ditolak, jam kerja dari DB; lokasi luar radius/akurasi/posisi lama/foto palsu ditolak tanpa menyimpan; koreksi lupa pulang & lupa absen (masa depan, urutan jam, ganda, tumpang tindih, pemohon/izin/tenant); papan, rekap (jumlah menit = DB), ekspor, foto berizin; data karyawan (kode unik, tautan akun, nonaktif saat masih masuk ditolak); pengaturan lokasi; retensi 90 hari |
| Live suite lengkap | **125/125** | 0 | per kelas: AccessControl 19, Attendance 7, Bootstrap 4 (run 9 Nov sebelum jaringan putus), Catalog 21 (setelah D19), Dashboard 5, LoginSecurity 14, OrderEngine 19, OutletSettings 6, PaymentFlow 18, Seed 4, SupabaseSession 8; kelas yang terputus D18 dijalankan ulang satu per satu |
| E2E suite penuh | **43/43 (494 assertion)** | 0 | termasuk `AttendanceFlowTest` 4/4 (upload multipart ke bucket privat asli lalu dibaca lewat route berizin, koreksi via kotak Persetujuan, rekap + ekspor, karyawan, pengaturan, batas akses) dan `OutletsTest` (lembar QR hanya meja aktif, manager 403) |
| Browser `probe-m7` (baru) | **35/35** | 0 | Super Admin atur lokasi dari GPS perangkat + tambah karyawan; kasir di HP: Absen → selfie → lokasi ±11 m → kirim; di luar radius (111 m) tombol kirim nonaktif → cek lokasi lagi → pulang; Owner: papan + foto, koreksi disetujui, rekap 2 hari + ekspor, kartu dasbor |
| Browser `probe-qr` (baru) | **10/10** | 0 | 5 kartu meja aktif, isi QR = URL pesan per meja, SVG terisi, mode cetak 2 kolom tanpa tombol, URL QR membuka menu meja |
| Regresi `probe-ux5b` | **15/15** | 0 | |
| Regresi `probe-ux5`, `probe-ux4` | **49/49**, **56/56** | 0 | diulang dengan server QA baru setelah D17/D18 (run pertama 46/49 dan 31/33 karena batas 30 detik) |

### Defect dan perbaikan

| No | Temuan | Jenis | Perbaikan |
|---|---|---|---|
| D12 | Pesan bentrok kode karyawan bisa salah ("akun sudah ditautkan") karena pesan exception memuat seluruh SQL insert (selalu berisi `user_id`) | Bug aplikasi (ditemukan saat review test) | Dibaca dari pesan driver `Key (user_id)=…`; dicek test Live |
| D13 | Halaman Absen: umur posisi GPS salah dihitung (memakai umur fix, bukan waktu diterima) sehingga posisi bisa selalu diambil ulang | Bug aplikasi (review kode) | Simpan waktu diterima; posisi < 60 detik dipakai dengan umur diperbarui |
| D14 | Durasi kerja "0 m" / "8 j 0 m" rancu dengan meter | UX | "45 mnt", "8 j", "2 j 15 mnt" |
| D15 | Ekspektasi test rekap ≥ 600 menit, padahal koreksi -1 jam dibulatkan ke menit (599) | Bug test | Dibandingkan tepat dengan jumlah menit di DB |
| D16 | Probe M7: izin lokasi CDP hilang karena koneksi browser ditutup setelah tiap perintah | Bug probe | Koneksi browser tetap untuk izin |
| D17 | Request sesekali melewati batas 30 detik PHP server QA lokal (dasbor, pembayaran detail) saat menunggu DB dev di Eropa | Lingkungan (latensi DB dev) | Diulang; produksi direncanakan satu region (Singapura) |
| D18 | Laptop mati/sleep saat Live suite: 93 test gagal karena DNS (`could not translate host name`), satu fixture tertinggal | Lingkungan | Fixture `ecfe6145` dibersihkan; kelas yang terputus dijalankan ulang |
| D19 | Live CatalogTest mengharapkan "Grup modifier tidak valid.", padahal UX-1 sengaja mengganti label menjadi "Grup pilihan tidak valid." | Test basi sejak UX-1 | Ekspektasi disamakan (assertion tetap persis), commit `7616db6` |

### Data, proses, Git

- Migration `2026_11_09_000001` sudah dijalankan di dev (enum: `biometric, manual_correction, gps_selfie`).
- Database dev bersih setelah QA: 0 company/user test, 0 karyawan/absen/koreksi/pengaturan absen, 0 foto selfie di bucket; tersisa company seed `sudaya`.
- Dependency baru: `qrcode-generator` 2.0.4 (MIT, tanpa dependency turunan; `npm audit --omit=dev` 0 kerentanan).
- Commit lokal M7-lite dibuat setelah QA; **belum di-push**. Commit UX-5 `d115d21` dan perbaikan test `7616db6` juga belum di-push. GitHub `main` tetap `df80e00`.
- Tidak ada proses QA (server lokal, Chrome headless) yang berjalan.

### Risiko tersisa

- Koordinat GPS dari browser bisa dipalsukan (aplikasi mock location); foto selfie menjadi bukti pendamping, pencocokan wajah belum ada (opsi D menyusul).
- GPS di dalam ruangan bervariasi per HP; radius/akurasi 30 m perlu diuji langsung di outlet dan disetel di Pengaturan absen.
- Retensi foto butuh cron `schedule:run` di server (sudah di STAGING_PREFLIGHT dan runbook).
- QR mencetak alamat web yang sedang dibuka; cetak dari domain produksi.
- Tugas luar (outside duty) dan jadwal shift/aturan telat belum ada.

## M5-lite stok, M6 laporan penjualan, tugas luar (9–10 November 2026)

**Status: Development Verified, menunggu review owner.** Keputusan owner 9 November: stok produk jadi **dipotong otomatis saat pembayaran** dan dikembalikan saat pembatalan disetujui (menggantikan penolakan pembayaran untuk menu bertaut stok). Tiga commit lokal per milestone + satu commit dokumen; **belum di-push**.

Tanpa migration baru (tabel `inventory_*`, `stock_opname*`, `outside_duty_requests`, enum `approval_type` sudah ada di skema dev). Tanpa perubahan izin peran (RBAC), harga server, alur kas/refund, CSP, atau CSRF.

| Bagian | Isi | File utama |
|---|---|---|
| Stok (M5-lite) | Item stok per outlet (bahan baku / produk jadi / kemasan, SKU unik, satuan + konversi g↔kg, ml↔l, stok minimum, harga beli, kedaluwarsa, saldo awal), **Stok masuk** (pembelian/retur/penyesuaian; harga beli terakhir), **Stok keluar & waste** (alasan wajib, foto bukti dari kamera ke bucket privat `inventory-evidence`), **Riwayat stok** (ledger append-only, idempotent per kunci), **Stock opname** (lembar dari saldo sistem, selisih + alasan → kotak Persetujuan; disetujui → koreksi sebesar selisih). Status Aman/Menipis/Habis di daftar dan dasbor. Produk jadi yang ditautkan ke menu: dipotong dalam transaksi pembayaran (`FOR UPDATE`, stok kurang → seluruh pembayaran batal) dan dibalik saat pembatalan disetujui | `Services/Inventory/*`, `Admin/{InventoryController,StockOpnameController}.php`, `pages/admin/inventory/*`, `OrderWorkflow.php` |
| Laporan penjualan (M6) | `/admin/reports` (report.sales: Owner, Manager, Super Admin): periode cepat (hari ini … bulan lalu) atau tanggal bebas ≤ 92 hari; omzet bersih, transaksi, rata-rata, item terjual dengan perbandingan periode sebelumnya; pesanan lunas yang dibatalkan/direfund terpisah; **Omzet harian** & **Jam ramai** (kolom satu warna, tooltip + keyboard + tabel kembaran), menu terlaris, per kategori, metode bayar; **Ekspor** satu file 6 sheet; **Cetak**. Angka sama dengan dasbor (pesanan lunas per tanggal operasional) | `Services/Reports/SalesReport.php`, `Admin/ReportController.php`, `pages/admin/reports/sales.tsx`, `XlsxWriter::workbook` |
| Tugas luar (PRD §15) | Staf: **Absen → Tugas luar outlet** (tanggal ≤ 60 hari ke depan, jam, lokasi, tujuan, keterangan, foto bukti opsional), batalkan selama menunggu. Owner/Manager (`attendance.approve_outside_duty`, bukan pemohon) memutuskan di **Persetujuan**. Selama jendela tugas yang disetujui radius outlet tidak berlaku saat absen (akurasi GPS, posisi segar, dan selfie tetap wajib; sesi menyimpan `outside_duty_request_id`). Tugas luar tampil di **Kehadiran**, dasbor "tugas luar menunggu persetujuan". Bukti hanya untuk pemohon & peninjau (`no-store`); retensi 90 hari ikut `sudaya:purge-attendance-photos` | `Services/Attendance/OutsideDuty.php`, `{Staff,Admin}/OutsideDutyController.php`, `pages/staff/outside-duty.tsx`, `AttendanceEngine.php` |
| Data payroll (PRD §60) | Ekspor rekap absensi kini 2 sheet: rekap (+ kolom sesi tugas luar) dan **Detail absen** (tanggal, jam masuk, jam pulang waktu lokal outlet, menit kerja, metode, tugas luar). Keterlambatan belum bisa dihitung karena belum ada jadwal shift | `AttendanceAdmin::exportReport`, `pages/admin/attendance/reports.tsx` |
| Navigasi | Sidebar: **Laporan penjualan** (Ringkasan), grup **Stok**; beranda staf: **Catat waste / stok keluar**, **Tugas luar**; tautan laporan di dasbor; layout admin rapi saat dicetak | `layouts/admin-layout.tsx`, `pages/staff/home.tsx`, `pages/admin/dashboard.tsx` |

### Hasil QA (Supabase dev eu-west-1, `APP_ENV=local`)

| Suite | Hasil | Exit | Catatan |
|---|---|---|---|
| Pint, TypeScript, build | lolos | 0 | |
| Unit/feature | 157/157 (608 assertion) | 0 | baru: `QtyTest` (konversi satuan), `XlsxWriterTest` multi-sheet |
| Live `InventoryTest` (baru) | **5/5 (85 assertion)** | 0 | master item (SKU, satuan, konversi), stok masuk/keluar idempotent + stok tidak boleh minus, tautan produk jadi, opname → persetujuan (pemohon tidak memutuskan sendiri), isolasi tenant |
| Live `PaymentFlowTest` | **19/19** | 0 | 18 lolos di run kelas penuh (1.153 detik); 1 test stok diperbaiki skenarionya (D20) lalu lolos di run ulang (9 assertion). Termasuk stok dipotong saat bayar & kembali saat batal, stok kurang membatalkan seluruh pembayaran tanpa jejak, stok terakhir diperebutkan dua kasir bersamaan → hanya satu lunas |
| Live `DashboardTest` | **5/5 (73 assertion)** | 0 | kartu stok + opname menunggu |
| Live `SalesReportTest` (baru) | **5/5 (36 assertion)** | 0 | laporan kosong, angka dari pesanan lunas (batal disetujui terpisah, belum bayar tidak dihitung), kategori & porsi, metode bayar, per jam di zona outlet = jam bayar di DB, periode pembanding + rentang terbalik + batas 92 hari, ekspor 6 sheet, izin (kasir/barista ditolak) dan isolasi tenant |
| Live `OutsideDutyTest` (baru) | **6/6 (84 assertion)** | 0 | validasi (lewat, jam terbalik, teks pendek, > 60 hari, akun tanpa karyawan), tumpang tindih, foto bukti; belum disetujui → radius tetap berlaku; disetujui → absen ±222 m diterima, akurasi tetap wajib, pulang setelah jendela habis tetap boleh untuk sesi tugas luar; pemohon (manager) tidak memutuskan sendiri; ditolak/dibatalkan tidak pernah aktif; bukti hanya pemohon & peninjau; tenant lain not found; retensi bukti 90 hari; ekspor payroll (kolom tugas luar, sheet Detail absen, jam lokal) |
| Live `AttendanceTest` (regresi) | **7/7 (143 assertion)** | 0 | dijalankan ulang setelah perubahan engine absen dan ekspor |
| E2E suite penuh | **47/47** | 0 | per kelas (satu proses per kelas, berurutan): Routes 18/18, InventoryFlow 3/3 (baru; upload bukti ke bucket asli, opname → Persetujuan → dasbor), AdminMonitoring 5/5 (+ laporan lewat HTTP: angka, tanggal tidak sah, rentang dipotong 92 hari, ekspor xlsx `no-store`, kasir/barista 403, tamu ke login, tenant lain melihat outletnya sendiri), AttendanceFlow 5/5 (+ tugas luar: form multipart + foto ke bucket asli, tumpang tindih ditolak, bukti untuk Owner `no-store` & staf lain 403, disetujui → absen ±222 m masuk & pulang, papan Kehadiran), PaymentFlow 5/5, Orders 6/6, Catalog 4/4, Outlets 2/2. Test tugas luar lolos di run ulang setelah D24/D25 |
| Browser `probe-m5` (baru) | **60/60** | 0 | Super Admin: item stok → stok masuk 2 l (7.000 ml) → waste + foto kamera (6.750 ml) → riwayat + foto berizin → opname: selisih −250 langsung tampil, alasan wajib → diajukan. Owner: kartu Stok + tautan laporan di dasbor, setujui opname → 6.500 ml, hanya pantau (tanpa Item baru). Laporan: omzet 69.000 = dasbor, 3 transaksi, rata-rata 23.000, menu terlaris, kategori 65,2 %, tunai; preset 7 hari → 7 kolom, tooltip saat hover, panah kiri dibaca `aria-live`, tabel kembaran, ekspor xlsx. Tablet & HP: kontrol ≥ 44 px, tanpa scroll horizontal. Kasir di HP: tile & tautan Tugas luar, form + foto, terkirim (Menunggu); Owner membuka foto bukti dan menyetujui; status Disetujui. Tanpa error console/CSP. Run 1–2 berhenti karena bug probe (D26) |

### Defect dan perbaikan

| No | Temuan | Jenis | Perbaikan |
|---|---|---|---|
| D20 | Live `PaymentFlowTest` "stok kurang membatalkan seluruh pembayaran" gagal di pembuatan pesanan: menu sudah menolak jumlah melebihi stok, sehingga skenario tidak pernah sampai ke pembayaran | Bug test (skenario) | Stok cukup saat pesanan dibuat lalu berkurang sebelum dibayar; assertion tetap sama persis |
| D21 | Pengajuan tugas luar gagal: `date + unknown` ambigu di PostgreSQL untuk batas 60 hari | Bug aplikasi (ditemukan Live test) | Parameter di-cast `::int` |
| D22 | Test E2E tugas luar menganggap akun barista belum punya data karyawan, padahal test rekap di kelas yang sama menautkannya | Bug test | Batas akses diuji lewat Owner (tanpa izin absen → 403) dan tamu |
| D23 | Laptop mati saat Live `AttendanceTest`; fixture `5682e39d` tertinggal | Lingkungan | Fixture dibersihkan; kelas dijalankan ulang |
| D24 | E2E tugas luar mengharapkan 404 untuk staf lain yang membuka bukti, padahal penjaga rute `/admin/outside-duty` sudah menolak dengan 403 | Bug test | Ekspektasi 403 (akses tetap ditolak) |
| D25 | E2E: setelah Owner membuka foto bukti, `back()` saat menyetujui kembali ke URL foto karena klien test tidak mengirim Referer (browser asli mengirim Referer halaman Persetujuan; `Referrer-Policy: strict-origin-when-cross-origin`) | Bug test | Test membuka kotak Persetujuan lagi sebelum memutuskan (pola test E2E lain) |
| D26 | Probe M5 memakai label tombol yang salah ("Tambah item", seharusnya "Item baru") dan setter textarea untuk kolom alasan opname yang berupa input | Bug probe | Diperbaiki; run 3 lolos 60/60 |

### Data, proses, Git

- Database dev bersih setelah QA: 0 company/user test, 0 item/gerakan/opname stok, 0 tugas luar, 0 objek bukti/selfie test di Storage; tersisa company seed `sudaya`.
- Tidak ada dependency baru.
- Commit lokal (belum di-push): `cec4dd6` (M5-lite stok), `5e0ab56` (M6 laporan penjualan), `d0f8c81` (tugas luar + data payroll), lalu commit dokumen ini. Commit sebelumnya yang juga belum di-push: `d115d21` (UX-5), `7616db6`, `5d7700e` (M7-lite). GitHub `main` tetap `df80e00`.

### Risiko tersisa

- QRIS dan pengenalan wajah menunggu provider resmi (tidak diaktifkan tanpa provider sah).
- Fase 2 PRD (supplier, purchase order, resep/BOM + potong bahan baku otomatis, loyalty, CRM, promo/voucher, pengeluaran) butuh tabel baru (migration) dan keputusan owner; promo/voucher/loyalty mengubah harga sehingga perlu aturan bisnis tertulis.
- Keterlambatan (telat) butuh jadwal shift per karyawan.
- Laporan per jam memakai jam bayar; pesanan lama dari sistem lain tidak ada.
- Stok bahan baku belum berkurang otomatis per penjualan (butuh resep/BOM, Fase 2); dicatat lewat stok keluar dan opname.

## Fase 2 PRD — supplier & purchase order, pengeluaran, resep/BOM (10 November 2026)

**Status: Development Verified, menunggu review owner.** Atas arahan owner 10 November ("gass selesaikan semuanya"). Commit lokal `f687503` (supplier/PO + pengeluaran + pelanggan) dan `8510981` (resep/BOM, terpisah karena menyentuh jalur pembayaran) + commit dokumen; **belum di-push**. Database dev bersih setelah QA (hanya company seed `sudaya`).

| Bagian | Isi | File utama |
|---|---|---|
| Migration (dev, 10 Nov) | `2026_11_10_000001`: nilai `purchase_order` pada enum `approval_type` (aditif, di luar transaksi). `2026_11_10_000002`: tabel baru `suppliers`, `purchase_orders`, `purchase_order_items`, `expenses` (RLS aktif tanpa policy, uang bigint rupiah, kuantitas numeric 14,3). Tabel lama tidak diubah | `database/migrations/` |
| Supplier & purchase order (PRD §26–27) | Supplier level company (nama unik, kontak, produk, termin, catatan, aktif) + histori (jumlah PO, total diterima, PO terakhir). PO per outlet: barang + jumlah dengan konversi satuan + harga per satuan → total → langsung diajukan ke **Persetujuan** (nomor `PO-yymmdd-NN` berurutan per outlet). Disetujui → **Terima barang** (jumlah yang benar-benar datang; 0 = tidak datang) → stok masuk "Pembelian" lewat ledger yang sama (idempotent per PO+item) + harga beli diperbarui. Ditolak/dibatalkan tidak bisa diterima | `Services/Purchasing/Purchasing.php`, `Admin/PurchasingController.php`, `pages/admin/purchasing/*` |
| Pengeluaran (PRD §59, §69) | Tanggal (≤ hari ini, ≤ 60 hari ke belakang), kategori (parkir, transport, pembelian kecil, perawatan, kebersihan, lainnya), nominal, keterangan, foto nota (bucket privat `inventory-evidence`). Pengaju melihat miliknya; peninjau melihat semua + total disetujui/menunggu per bulan & per kategori. Persetujuan Owner/Manager, bukan pemohon; batalkan sendiri selama menunggu. Laporan penjualan menampilkan pengeluaran disetujui dan omzet dikurangi pengeluaran (juga di ekspor). Saldo laci kas tidak diubah | `Services/Finance/Expenses.php`, `Admin/ExpenseController.php`, `pages/admin/expenses/index.tsx` |
| Resep/BOM (PRD §93) | Tabel `product_recipe_items` yang sudah ada: bahan per 1 porsi per outlet (konversi satuan, tanpa duplikat, item produk jadi menu itu ditolak), perkiraan modal bahan dari harga beli terakhir. Saat bayar, bahan dipotong bersama produk jadi dalam transaksi yang sama (urutan item tetap); **bahan tidak pernah menolak penjualan** — stok tidak boleh minus, jadi dipotong sampai habis dan kekurangannya (`shortfall`) dicatat di log aktivitas `inventory.sale_posted`. Pembatalan lunas yang disetujui membalik semua potongan. Produk jadi tetap ketat (keputusan 9 Nov) | `Services/Inventory/{Recipes,InventoryLedger}.php`, `Admin/RecipeController.php`, `pages/admin/inventory/recipes/*` |
| Data pelanggan (PRD §51–55, hanya-baca) | Dihitung dari pesanan lunas yang mencantumkan nomor HP (checkout QR/kiosk, delivery POS): transaksi, total belanja, rata-rata, terakhir/pertama, menu favorit, poin (contoh PRD Rp10.000 = 1 poin; otomatis turun bila batal/refund karena hanya lunas yang dihitung), tier Member/Silver ≥ Rp300.000/Gold ≥ Rp1.000.000. Nomor disamarkan di daftar (lihat lengkap dengan ikon mata). Tidak mengubah checkout; penukaran poin belum ada | `Services/Customers/CustomerInsights.php`, `Admin/CustomerController.php`, `pages/admin/customers/index.tsx` |
| Izin (tanpa perubahan RBAC) | Memakai izin yang sudah ada: supplier/PO/terima barang = `inventory.receive` (Manager, Admin), lihat = `inventory.view`; resep ubah = `inventory.adjust`; pengeluaran dicatat oleh pemegang `cash.view` atau `inventory.issue` (Kasir, Barista, Manager, Owner), ditinjau `approval.view`, diputuskan `approval.approve`. Peta rute ditambah `/admin/suppliers`, `/admin/purchase-orders`, `/admin/expenses`, `/admin/inventory/recipes`, `/admin/customers` (customer.view) | `RouteAccess.php`, `HandleInertiaRequests.php` |
| Navigasi | Sidebar: Resep (BOM), Purchase order, Supplier (grup Stok); Pengeluaran (Operasional); beranda staf: Catat pengeluaran; dasbor: "pengeluaran/PO menunggu persetujuan"; kotak Persetujuan: kartu PO & pengeluaran | `layouts/admin-layout.tsx`, `pages/admin/{approvals,dashboard}.tsx`, `pages/staff/home.tsx` |

### Hasil QA (Supabase dev eu-west-1, `APP_ENV=local`)

| Suite | Hasil | Exit | Catatan |
|---|---|---|---|
| Pint, TypeScript, build | lolos | 0 | |
| Unit/feature | 157/157 | 0 | |
| Live `PurchasingExpenseTest` (baru) | **4/4 (73 assertion)** | 0 | supplier (validasi, nama ganda, izin, tenant); PO 2 kg → 2.000 g @ Rp150/g total Rp300.000, belum disetujui tidak bisa diterima, pemohon tidak memutuskan, terima sebagian 1.800 g → stok & harga beli, terima ulang ditolak; PO ditolak/dibatalkan, nomor berurutan, isolasi tenant; pengeluaran (validasi, foto nota, visibilitas pengaju vs peninjau, keputusan, total per kategori, masuk laporan penjualan, bukti berizin, batal sendiri) |
| Live `RecipeTest` (baru) | **3/3 (25 assertion)** | 0 | validasi resep (duplikat, produk jadi, izin, tenant), 2 latte + 1 croissant → bahan & produk jadi terpotong, batal disetujui → kembali; susu kurang → bayar tetap lunas, susu 0 + shortfall 50 ml di log; susu habis → tanpa movement; produk jadi kurang → seluruh pembayaran batal termasuk bahan resep (rollback) |
| Live `CustomerInsightsTest` (baru) | **1/1 (9 assertion)** | 0 | agregasi per nomor HP (nama terbaru, 2 transaksi, Rp330.000, rata-rata, 33 poin, Silver, favorit), pesanan batal disetujui tidak dihitung, pesanan tanpa HP tidak tercatat, cari nama/nomor, barista ditolak, tenant lain kosong |
| Live regresi | **SalesReport 5/5, Dashboard 5/5, PaymentFlow 19/19 (227 assertion)** | 0 | jalur pembayaran berubah (resep) sehingga PaymentFlowTest dijalankan ulang penuh |
| E2E | **35/35** | 0 | `Phase2FlowTest` (baru) 4/4 (47 assertion): pengeluaran + foto nota ke bucket asli → Persetujuan → rekap & laporan, nota untuk staf lain 404; supplier → PO 1 kg → disetujui → terima 1.000 g → stok 1.500 g & harga beli Rp140/g, kasir 403; resep lewat HTTP (simpan, barista ditolak); halaman Pelanggan (barista 403). Regresi: AdminMonitoring 5/5, PaymentFlow 5/5, Routes 18/18, InventoryFlow 3/3. Kelas E2E lain tidak tersentuh perubahan Fase 2 (lolos 47/47 di run sebelumnya hari ini) |
| Browser `probe-p2` (baru) | **42/42** | 0 | Kasir di HP: pintasan → catat pengeluaran + foto nota (kontrol ≥ 44 px, tanpa scroll horizontal) → Menunggu. Super Admin: sidebar 4 menu baru, supplier lewat dialog, item stok, PO 2 kg @ Rp150.000/kg (pratinjau Rp300.000) → diajukan, resep Lemon Tea (18 g) tersimpan. Owner: dasbor "pengeluaran/PO menunggu persetujuan", kartu PO & pengeluaran, nota berizin, setujui keduanya, rekap Rp12.500, halaman Pelanggan. Terima barang → stok 3.000 g. Tablet & HP: 5 halaman baru tanpa scroll horizontal. Tanpa error console/CSP. Run 1: 41/42 (D28) |

### Defect dan perbaikan

| No | Temuan | Jenis | Perbaikan |
|---|---|---|---|
| D27 | Owner ikut bisa mencatat pengeluaran karena memegang `cash.view` (kas hanya-lihat); test awal mengharapkan ditolak | Bug test (aturan izin) | Dibiarkan sesuai izin yang ada (pengajuan Owner tetap diputuskan Manager/Owner lain); test memeriksa tenant lain ditolak |
| D28 | Probe: cek "supplier tersimpan" menunggu toast sukses, padahal simpan di DB dev ±12 detik (POST 6,3 dtk + muat ulang 5,5 dtk, diukur dari jaringan browser) sehingga toast sudah hilang saat dialog tertutup | Bug probe | Cek hasil yang menetap (dialog tertutup + supplier tercantum); perilaku aplikasi benar |

### Catatan Git

- Remote `main` kini `a0edbfa` (kontributor `bani1610`, 9 Nov 17.08: dasbor & pesanan admin versi lain) sehingga commit lokal tidak bisa di-push fast-forward. Uji merge di worktree terpisah: 8 file konflik (`AdminOrderController`, `DashboardController`, `HandleInertiaRequests`, `OrderEngine`, `admin-layout`, `dashboard.tsx`, `routes/web.php`, `DashboardTest`). Butuh keputusan owner/tim sebelum push; tidak ada force push.

### Risiko tersisa

- Promo/voucher dan loyalty/CRM belum dibuat: mengubah harga checkout (aturan yang dikunci owner) dan butuh aturan bisnis + persetujuan.
- Resep memakai jumlah per porsi tetap; varian (mis. Large, extra shot) belum menambah bahan.
- Izin modul baru menumpang izin lama; bila owner ingin izin khusus (mis. `expense.create`, `purchase.manage`), perlu perubahan RBAC terpisah.
- PO satu kali penerimaan; penerimaan bertahap (partial berkali-kali) belum ada.

## Pesanan meja kantor, QRIS statis, notifikasi, menu contoh (10 November 2026, untuk presentasi)

**Status: Development Verified, menunggu review owner.** Permintaan owner 10 November: QR di tiap meja kantor untuk pesan & bayar; antar oleh barista dikenakan biaya (nominal diatur admin, awal 0); pemesan mengisi nama, meja, lantai, bagian; catatan seperti less sugar/less ice; request di luar menu; notifikasi saat pesanan siap; menu contoh untuk presentasi. Bayar memakai QRIS milik Sudaya (asal QRIS belum diketahui owner → QRIS statis dengan konfirmasi manual kasir).

| Bagian | Isi | File utama |
|---|---|---|
| Migration (dev, 10 Nov) | `2026_11_10_000003`: `outlets.delivery_fee` (0–1.000.000), `outlets.qris_image_path`, `orders.delivery_fee` — aditif | `database/migrations/` |
| Checkout QR meja | Antar ke meja (biaya antar outlet, dihitung server, disimpan per pesanan, masuk total/tagihan/struk/laporan) atau Ambil di counter (tanpa biaya). Lantai & bagian wajib di form (meja dari QR), disimpan sebagai keterangan lokasi "Lantai 3 · Keuangan". Kolom "Pesan menu di luar daftar" masuk catatan pesanan | `CheckoutInput.php`, `OrderEngine::create`, `components/storefront/storefront.tsx` |
| QRIS statis | Admin mengunggah gambar QRIS di **Outlet → Pesanan meja kantor** (bucket publik, gambar lama dihapus, cache menu dibuang). Pelanggan yang memilih QRIS melihat QRIS + nominal persis + tombol simpan. Kasir: **Konfirmasi QRIS diterima** setelah cek dana (izin `payment.confirm_cash`), tanpa sesi kas/kembalian; tunai & QRIS memakai satu jalur `settle()` (kunci order, nomor antrean, potong stok, audit `manualConfirmation`). Tanpa gambar QRIS: pilihan QRIS ditolak | `OrderWorkflow::{confirmQris,settle}`, `OutletSettings::updateOrdering`, `pages/order/status.tsx`, `pages/pos/orders/show.tsx`, `pages/admin/outlets/edit.tsx` |
| Catatan & notifikasi | Catatan cepat per item (Less sugar, No sugar, Less ice, Tanpa es, Extra panas, Tanpa sedotan). Halaman status: tombol aktifkan notifikasi; saat **siap** muncul banner + bunyi + getar + notifikasi browser. Barista & detail kasir: **Kabari via WhatsApp** (wa.me, tanpa API berbayar) bila pelanggan mengisi nomor; kartu barista **ANTAR KE MEJA** | `components/catalog/product-options.tsx`, `lib/whatsapp.ts`, `pages/kds/index.tsx` |
| Menu contoh | `php artisan sudaya:demo-menu` (ditolak di production): 9 menu contoh, 3 kategori, varian ukuran/gula/es/tambahan lewat service katalog; SKU `CONTOH-`, deskripsi "contoh"; `--remove` menghapus. Dipasang di company `sudaya` dev. Placeholder foto "Foto menyusul" | `Console/Commands/DemoMenuCommand.php` |
| Presentasi | Naskah demo 3 perangkat | `docs/DEMO_PRESENTASI.md` |

### Hasil QA (Supabase dev eu-west-1, `APP_ENV=local`)

| Suite | Hasil | Exit | Catatan |
|---|---|---|---|
| Pint, TypeScript, build, unit/feature | lolos, **157/157 (608 assertion)** | 0 | |
| Live `DeskOrderTest` (baru) | **3/3 (33 assertion)** | | pengaturan biaya/QRIS (izin, batas, ganti gambar menghapus lama, audit, menu publik), antar ke meja 2×25.000 + 3.000 = 53.000 dengan lokasi & catatan, ambil sendiri tanpa biaya, QRIS dikonfirmasi kasir tanpa masuk laci, barista ditolak, idempotent, QRIS hilang setelah lunas, tanpa gambar QRIS ditolak, tunai termasuk biaya antar |
| Live `PaymentFlowTest` (regresi `settle()`) | **19/19 (227 assertion)** | 0 | |
| Live `OrderEngineTest` | **19/19 (141 assertion)** | | 2 ekspektasi disesuaikan dengan perilaku baru (audit `deliveryFee`, pesan QRIS) |
| E2E `DeskFlowTest` (baru, HTTP sungguhan) | **2/2 (20 assertion)** | 0 | admin mengunggah QRIS + biaya antar lewat multipart persis seperti browser, kasir ditolak di halaman admin; pesan dari QR meja (lantai, bagian, catatan, request di luar menu, QRIS), barista ditolak konfirmasi, kasir konfirmasi, kartu barista lengkap dengan nomor WA, QRIS hilang setelah lunas |
| E2E `OrdersTest` (regresi) | **6/6 (96 assertion)** | 0 | pesan QRIS disesuaikan |
| E2E `PaymentFlowTest`, `OutletsTest` (regresi) | **5/5 (100 assertion)**, **2/2 (36 assertion)** | 0 | |
| Browser Chrome (HP 375 px, tablet, desktop) | **29/29** | 0 | admin simpan biaya antar Rp 3.000 + gambar QRIS → pelanggan scan QR meja L3-01, catatan cepat "Less ice", lantai & bagian, request di luar menu, QRIS + nominal Rp 18.000 → kasir konfirmasi QRIS → HP berubah lunas sendiri → barista ANTAR KE MEJA, tandai siap, tombol Kabari via WhatsApp → banner siap di HP; kontrol ≥ 44 px, tanpa scroll horizontal, tanpa error console/CSP |

### Defect dan perbaikan

| No | Temuan | Jenis | Perbaikan |
|---|---|---|---|
| D29 | Menu pelanggan di-cache sehingga biaya antar/QRIS baru tidak langsung tampil | Bug aplikasi (review) | Cache outlet dibuang saat pengaturan pesanan meja disimpan |
| D30 | Test membandingkan log audit dengan urutan kunci; `jsonb` menyusun ulang kunci | Bug test | Perbandingan nilai tanpa urutan kunci (nilai tetap persis) |
| D31 | OrderEngineTest mengharapkan pesan "online belum tersedia" dan audit tanpa `deliveryFee` | Ekspektasi lama | Disesuaikan ke perilaku baru yang diminta owner |
| D32 | Simpan biaya antar/QRIS dari layar admin ditolak "Input tidak valid": form multipart mengirim `removeQris` sebagai teks "0", service hanya menerima boolean | Bug aplikasi (uji browser) | Controller membaca `removeQris` sebagai boolean form (pola sama dengan hapus foto menu); E2E kini mengirim payload persis seperti browser |
| D33 | Tombol "Antar ke meja"/"Ambil di counter" di HP setinggi 42 px (< 44 px) | Bug UI (uji browser) | Tinggi minimum 44 px |
| D34 | E2E: flash penolakan barista hilang karena `back()` tanpa halaman sebelumnya | Bug test | Barista membuka layar barista dulu (pola sama dengan kasir) |
| D35 | Getar HP dipanggil sebelum pelanggan menyentuh halaman → peringatan console Chrome | Bug UI (uji browser) | Bunyi & getar hanya setelah ada sentuhan; banner & notifikasi browser tetap tampil |

### Risiko & keputusan berikutnya

- **Harga request di luar menu**: tercatat dan terlihat staf, tetapi belum bisa diberi harga di sistem. Pesanan dibuat barista setelah lunas, jadi menambah harga sesudah antar butuh aturan bayar baru (mis. bayar saat diantar atau harga diisi kasir sebelum bayar) — keputusan owner.
- **QRIS statis**: dana tidak terverifikasi otomatis; kasir wajib cek aplikasi bank/QRIS sebelum konfirmasi. QRIS otomatis butuh payment gateway resmi.
- **WhatsApp otomatis** butuh WhatsApp Business API berbayar (PRD Fase 3); saat ini tombol WA manual + notifikasi web (berlaku selama halaman status terbuka; push saat browser tertutup butuh hosting HTTPS + service worker).
- Lantai & bagian divalidasi di form; server menyimpan bila dikirim (tidak diwajibkan server agar alur QR meja lama tetap kompatibel).
- QR dicetak dari domain presentasi/production, bukan laptop.

## Aplikasi staf di HP (PWA, siap dibuat APK) — 11 November 2026

**Status: Development Verified, menunggu review owner.** Permintaan 11 November: "biar karyawan or baristanya juga bisa absen lewat aplikasi", lalu web diserahkan ke klien sebagai aplikasi. Web kini bisa **dipasang sebagai aplikasi di HP staf** (Android lewat Chrome, iPhone lewat Safari), tanpa Play Store dan tanpa dependency baru. APK terpisah dibuat setelah domain HTTPS production ada (langkah di `docs/APLIKASI_HP.md`).

| Bagian | Isi | File utama |
|---|---|---|
| Manifest | Nama "Sudaya Coffee"/"Sudaya", `start_url` `/login` (staf yang sudah masuk langsung diarahkan ke halaman perannya), layar penuh, ikon 192/512/maskable + apple-touch-icon, jalan pintas **Absen**, **Barista**, **Kasir** | `public/manifest.webmanifest`, `public/icons/` |
| Hanya area staf | Manifest & service worker hanya dipasang di halaman staf; beranda, menu/QR, status pesanan, dan kiosk tidak menawarkan pemasangan | `App\Support\StaffApp::offeredOn`, `resources/views/app.blade.php` |
| Service worker | Navigasi halaman selalu dari jaringan; saat offline tampil halaman "Tidak ada koneksi internet". Tidak ada halaman, data Inertia, foto, atau API yang di-cache (data login, pesanan, absen tidak tersimpan di HP). Tanpa perubahan CSP (`'self'` sudah mengizinkan) | `public/sw.js`, `public/offline.html` |
| Ajakan pasang | Kartu **Pasang** (Android/Chrome, banner otomatis browser dicegah) atau petunjuk **Bagikan → Tambahkan ke Layar Utama** (iPhone); tampil di login, beranda staf, dan halaman absen; bisa ditutup (disembunyikan 14 hari); hilang bila sudah dibuka sebagai aplikasi | `lib/install-prompt.ts`, `components/install-app.tsx` |
| Siap APK | `/.well-known/assetlinks.json` dari env `ANDROID_APP_PACKAGE` + `ANDROID_APP_SHA256` (divalidasi; 404 bila kosong) agar APK hasil PWABuilder/Bubblewrap terbuka tanpa bilah alamat | `AppLinksController`, `config/services.php`, `.env.example` |
| Ikon | Placeholder monogram "S" warna brand sampai logo resmi tersedia (cara ganti di `docs/APLIKASI_HP.md`) | `public/icons/` |

### Hasil QA (lokal, `APP_ENV=local`; database dev hanya untuk fixture login)

| Suite | Hasil | Exit | Catatan |
|---|---|---|---|
| Pint, TypeScript, build | lolos | 0 | |
| Unit/feature (SQLite) | **163/163 (657 assertion)** | 0 | baru: `Unit\StaffAppTest` (area staf vs pelanggan, manifest & ukuran ikon, service worker tanpa cache data, validasi assetlinks) dan `Feature\StaffAppTest` (manifest di `/login`, tidak di `/`; assetlinks 404 → JSON setelah dikonfigurasi) |
| Browser Chrome (HP 375 px, UA Android & iPhone) | **28/28** | 0 | file aplikasi 200, manifest terbaca Chrome tanpa error, **syarat pasang Chrome terpenuhi** (`Page.getInstallabilityErrors` kosong), service worker aktif & mengendalikan halaman, tombol Pasang memanggil dialog pasang, offline → halaman offline Sudaya (ikon tetap tampil), petunjuk iPhone + tutup tersimpan, menu pelanggan tanpa manifest/ajakan, barista login dari `start_url` lalu jalan pintas Absen, kontrol ≥ 44 px, tanpa scroll horizontal, tanpa error console/CSP |

### Defect dan perbaikan

| No | Temuan | Jenis | Perbaikan |
|---|---|---|---|
| D36 | Halaman offline menampilkan ikon rusak saat tidak ada jaringan (gambar dimuat dari server) | Bug aplikasi (uji browser) | Ikon disematkan langsung di halaman offline; service worker hanya menyimpan halaman offline |
| D37 | Kartu "Pasang aplikasi" sempit di HP 375 px (teks terlipat 4 baris di samping tombol) | Bug UI (uji browser) | Tombol **Pasang aplikasi** dipindah ke bawah teks, selebar kartu |
| D38 | Probe membaca nama manifest dari field CDP yang kosong dan mengecek syarat pasang di konteks terisolasi (= incognito, selalu `in-incognito`) | Bug test | Manifest dibaca dari isi mentah; syarat pasang dicek di profil biasa |
| D39 | Sekali login barista di probe pertama tidak selesai dalam 120 detik; dua run berikutnya lolos | Lingkungan | Request login adalah XHR (bukan navigasi) sehingga tidak disentuh service worker; pola latensi DB dev eu-west-1 yang sama sudah tercatat sebelumnya. Diulang di staging |

### Risiko & keputusan berikutnya

- **APK terpisah** baru bisa dibuat setelah domain HTTPS production aktif (APK membungkus alamat itu). Pemasangan dari Chrome Android sudah menghasilkan aplikasi Android sungguhan (WebAPK) tanpa file APK.
- Absen tetap butuh internet (disengaja agar jam, lokasi, dan foto dicatat server). Tidak ada antrean absen offline.
- Notifikasi push saat aplikasi tertutup (mis. pesanan baru untuk barista) butuh Web Push + kunci VAPID di server production; belum dibuat.
- Ikon masih placeholder sampai logo resmi tersedia.
