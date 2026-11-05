# Kesiapan go-live Sudaya Coffee (Laravel)

Diperbarui 5 November 2026. Status per butir: **PASS** (terbukti dengan test atau QA yang tercatat), **FAIL**, **BLOCKED** (menunggu keputusan atau pihak luar), **NOT TESTED**.

**Kesimpulan:** belum siap production. Fitur transaksi tunai inti terbukti di lingkungan development (database dan Supabase dev). Hosting, database production, HTTPS, backup/restore, monitoring, data menu asli, perangkat outlet, dan UAT belum ada. Seluruh PASS di bawah adalah *Development Verified*, bukan bukti production.

## Fitur

| Butir | Status | Bukti |
|---|---|---|
| POS: buat pesanan, modifier, catatan, tipe pesanan | PASS | Live `OrderEngineTest`, E2E `OrdersTest`, browser M3 + M4 (HP, tablet, desktop) |
| QR order meja/kasir tanpa login | PASS | E2E `OrdersTest`, `PaymentFlowTest`, browser. Kode outlet divalidasi. Sejak 4 November QR meja dine in hanya untuk meja terdaftar dan aktif (Live `OrderEngineTest`, `OutletSettingsTest`) |
| Pengaturan outlet dan daftar meja (`/admin/outlets`, butuh `settings.manage`) | PASS | Unit `OutletInputTest`, Live `OutletSettingsTest` 6/6, E2E `OutletsTest` 2/2, browser 22/22. Telepon outlet belum ada di form |
| Halaman depan kafe dan menu customer (redesign 5 November): cara pesan, menu pilihan dari data asli, pencarian menu, kategori aktif | PASS | Unit `HealthTest` (`/` tetap tampil tanpa database), browser redesign 59/59, regresi browser M3 137/137, E2E penuh 35/35 |
| Kiosk: hanya dine in/takeaway, tanpa token di perangkat bersama, kode referensi | PASS | E2E kiosk → kasir bayar → KDS, browser kiosk landscape. Nomor antrean diberikan lewat struk kasir (keputusan sementara EXT-012); tampilan nomor di kiosk belum ada |
| Pembayaran tunai: uang diterima, kembalian, idempotent, hanya kasir/manager | PASS | Live `PaymentFlowTest` (termasuk 3 proses bayar order yang sama), E2E, browser |
| Sesi kas: buka, kas masuk/keluar, tutup dengan uang fisik, selisih beralasan | PASS | Live (rekonsiliasi angka per komponen), E2E tutup kas cocok, browser |
| Nomor antrean unik per outlet per hari, berurutan sesuai waktu bayar | PASS | Live 4 pembayaran bersamaan → nomor berurutan; constraint unik `(outlet_id, business_date, queue_number)` |
| KDS New → Preparing → Ready → Completed, hanya order lunas, FIFO | PASS | Live, E2E, browser. Mode realtime `live`; sinyal tiba sebelum kasir menerima respons |
| Status pelanggan mengikuti pembayaran dan KDS | PASS | Live, E2E, browser. Supabase Realtime push terukur: sinyal tiba 227–240 ms setelah aksi kasir/barista; data tampil setelah satu request reload (±4,7 detik di server dev laptop) |
| Struk 80 mm: nomor antrean, metode, diterima, kembali, kasir, waktu bayar; BELUM DIBAYAR / DIREFUND | PASS | E2E props struk; browser mode print → PDF 80 mm, satu halaman, tinggi mengikuti isi. Printer fisik: NOT TESTED |
| Pembatalan: belum bayar langsung batal beralasan; lunas butuh approval Manager lain | PASS | Live (self-approval ditolak), E2E, browser |
| Refund tunai penuh dari sesi kas terbuka, tidak bisa dua kali | PASS | Live (2 proses refund bersamaan → 1 refund), E2E, browser |
| Sold out manual per outlet | PASS | M2: Live/E2E `CatalogTest`, browser M2 |
| Produk tertaut stok: pembayaran ditolak sampai pemotongan stok ada (M5) | PASS | Live (tanpa perubahan apa pun, termasuk 2 proses bersamaan), E2E |
| Refund setelah pesanan `completed`, refund parsial, QRIS | BLOCKED | Tidak tersedia. Prosedur manual: Manager mencatat **kas keluar** beralasan di sesi kas. QRIS menunggu payment gateway (EXT-001) |
| Laporan penjualan, ekspor transaksi | NOT TESTED | Modul M6 ditunda. Sementara: daftar Pesanan → tampilan **Lunas** per hari dan riwayat tutup kas per sesi |
| Stok, absensi | NOT TESTED | M5 dan M7 ditunda sesuai keputusan owner |

## Keamanan

| Butir | Status | Bukti |
|---|---|---|
| Autentikasi Supabase, sesi terenkripsi, logout mencabut sesi | PASS | M1: Live/E2E/browser |
| RBAC per permission dan scope outlet (kasir, barista, manager, owner) | PASS | Live + E2E M4: barista tidak bisa bayar/buka kas, kasir tidak bisa KDS/refund, owner hanya lihat |
| Isolasi company | PASS | Live: order, kas, approval, refund company lain → not_found |
| CSRF | PASS | E2E: tanpa token 419 (checkout, bayar) |
| Origin checkout publik | PASS | E2E: origin asing 403 |
| Rate limit login dan checkout | PASS | M1 Live, M3 E2E (429 + Retry-After) |
| Keamanan order guest (token hash, cookie HttpOnly per order, data publik saja) | PASS | M3 Live/E2E |
| Idempotensi order, pembayaran, refund | PASS | Live konkurensi multi-proses |
| Audit trail (order, pembayaran, antrean, status, kas, pembatalan, approval, refund) | PASS | Live menghitung baris audit per aksi |
| Realtime tanpa membuka RLS bisnis; sinyal tanpa data | PASS | Live: payload hanya `id`; data dimuat ulang lewat server |
| Header keamanan dan CSP | PASS | M1; browser M3 + M4 tanpa error console maupun pelanggaran CSP |
| Uji penetrasi / review keamanan eksternal | NOT TESTED | M8 |

## Infrastruktur

| Butir | Status | Catatan |
|---|---|---|
| Hosting PHP (PHP 8.5, pdo_pgsql, koneksi keluar ke pooler Supabase) | BLOCKED | Belum dipilih/dibeli. Lihat `LARAVEL_HOSTING_REQUIREMENTS.md` |
| Supabase production terpisah dari development | BLOCKED | Belum dibuat |
| HTTPS dan domain | BLOCKED | EXT-021 |
| Konfigurasi environment production (`APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, `TRUSTED_IP_HEADER`, secret terpisah) | NOT TESTED | Panduan ada di `LARAVEL_DEPLOYMENT_GUIDE.md` |
| Scheduler kedaluwarsa order | PASS (dev) | Job pg_cron `sudaya-expire-pending-orders` tunggal, terbukti di dev. Harus dipasang di database production |
| Backup database | BLOCKED | Bergantung paket Supabase production (EXT-028) |
| Uji restore | NOT TESTED | |
| Monitoring error | NOT TESTED | M8 |
| Uji beban / performa dari server satu region dengan database | NOT TESTED | Angka dari laptop development tidak mewakili production |
| Prosedur rollback | PASS (dokumen) | `LARAVEL_DEPLOYMENT_GUIDE.md` §4, belum dipraktikkan |

## Operasional

| Butir | Status | Catatan |
|---|---|---|
| Menu dan harga aktual di sistem | NOT TESTED | Diisi admin lewat `/admin/products` di production |
| Akun staf dan role per outlet | NOT TESTED | Dibuat lewat `/admin/users` di production |
| Perangkat kasir (POS) | NOT TESTED | UI teruji di HP, tablet, desktop (Chrome) |
| Perangkat KDS | NOT TESTED | UI teruji di tablet landscape dan desktop |
| Printer struk thermal 80 mm | NOT TESTED | Ukuran halaman 80 mm terbukti lewat PDF; printer fisik belum dicoba |
| QR meja dicetak | NOT TESTED | Format URL `/order?outlet=<kode>&table=<meja>`. Meja wajib didaftarkan dulu di `/admin/outlets/<outlet>`; QR meja yang belum terdaftar menampilkan "QR meja tidak dikenali" |
| Internet / Wi-Fi outlet | NOT TESTED | Realtime butuh WebSocket ke Supabase; tanpa itu jatuh ke polling |
| Uji transaksi langsung di outlet | NOT TESTED | |
| UAT Manager (checklist di bawah) | NOT TESTED | |
| Persetujuan Owner | BLOCKED | Menunggu hasil UAT |

## Hasil QA M4 (Supabase dev, 4 November 2026)

| Lapis | Hasil | Exit code |
|---|---|---|
| Pint, TypeScript, build Vite | lolos | 0 |
| Unit/feature | 129/129 | 0 |
| Live (M1–M4) | 107/107, 637 assertion | 0 |
| E2E (M1–M4) | 32/33 pada regresi penuh (exit 1). Penyebab: test M3 berpremis "belum ada endpoint bayar"; sejak M4 endpoint itu ada dan guest mendapat 401. Test diperbarui menjadi kode status tepat per path, lalu `OrdersTest` 6/6 | 1, lalu 0 |
| Browser M3 (regresi) | 137/137 | 0 |
| Browser M4 | Utama 56/58, bagian 2 23/24, cek desktop 2/2, latensi realtime 12/12. Semua kegagalan tersisa adalah artefak server QA satu proses atau cek probe di layar HP; aplikasi dan data terverifikasi benar | 1 (probe) |

Defect yang ditemukan QA M4 dan sudah diperbaiki:
1. Ukuran halaman struk: `@page { size: 80mm auto }` tidak valid sehingga browser memakai Letter. Sekarang 80 mm × tinggi isi.
2. Nomor antrean di halaman status pelanggan terpotong dua baris di HP.
3. Hasil simpan/bayar POS lewat flash sesi bisa diambil reload tab lain di sesi yang sama, misalnya KDS di tablet kasir, sehingga nomor antrean dan kembalian tidak tampil. Sekarang POS membaca hasil dari respons JSON.

## Residual QA M4 (wajib ditutup sebelum staging/UAT dinyatakan lulus)

M4 disetujui owner sebagai *DONE — Development Verified* pada 4 November 2026 dengan catatan berikut.

| No | Residual | Catatan hasil awal | Penutupan |
|---|---|---|---|
| R1 | E2E regresi penuh setelah assertion test M3 diperbarui | Regresi penuh 4 November: **32/33** (exit 1). Penyebab: test M3 `OrdersTest > respons error checkout` berpremis "belum ada endpoint bayar". Sejak M4 endpoint itu ada untuk staf, dan guest mendapat 401. Setelah diperbarui, hanya `OrdersTest` yang dijalankan ulang (6/6) | **DITUTUP 5 November**: E2E penuh **35/35** (366 assertion, exit 0, 40 menit) termasuk fitur outlet/meja dan redesign. Database dev bersih sesudahnya |
| R2 | Browser M4 pada server yang mampu melayani beberapa layar bersamaan | Run utama **56/58**. Login manager gagal karena server QA `php -S` satu proses mengantre reload realtime dari 4 layar (±7,4 detik per request), dan probe berhenti. Bagian refund dan tutup kas diuji terpisah (23/24 + 2/2) | **DITUTUP 5 November**: server QA lokal 6 worker (proxy + 6 proses `php -S`), probe M4 **73/73** (exit 0) dengan kasir, KDS, pelanggan, dan manager bersamaan. Staging tetap wajib diuji ulang dengan PHP-FPM |
| R3 | Cek keranjang mobile sesuai kondisi responsif | Bagian 2 **23/24**: cek "POS meminta buka kas" dijalankan di layar HP, padahal keranjang di HP ada di sheet yang tertutup. Di desktop lolos 2/2 | **DITUTUP 5 November**: probe membuka sheet keranjang di HP; "setelah tutup kas, POS kembali meminta buka kas" PASS di run 73/73 |
| R4 | POS → bayar → KDS → Completed dalam satu run tanpa putus | Setiap tahap lolos di run terpisah (E2E dan browser), tetapi belum dalam satu run browser utuh | **DITUTUP 5 November**: satu run 73/73: buka kas → bayar tunai di desktop/tablet/HP (SDY-001..003 berurutan) → KDS realtime (mode `live`) → Preparing → Ready → Completed → status pelanggan "Selesai" → batal + approval manager lain → refund → tutup kas |

## Hasil QA tahap 2 (Supabase dev, 5 November 2026)

Lingkup: pengaturan outlet, daftar meja, halaman depan kafe, dan poles menu customer. Server QA lokal 6 worker; angka waktu dari laptop development, bukan ukuran production.

| Lapis | Hasil | Exit code |
|---|---|---|
| Pint, TypeScript, build Vite | lolos | 0 |
| Unit/feature | 142/142 (512 assertion) | 0 |
| E2E `CatalogTest` (fixture kini mendaftarkan meja T03; assertion tidak diubah) | 4/4 | 0 |
| Browser redesign (halaman depan, pencarian, chip kategori, kiosk; HP, tablet, desktop) | 59/59 | 0 |
| Browser M4 multi-layar (R2–R4) | 73/73 | 0 |
| Browser outlet & meja | 22/22 (run pertama berhenti karena selector probe `button` untuk Switch yang dirender sebagai `role=switch`; selector diperbaiki, outlet fixture dikembalikan, lalu diulang) | 0 |
| Browser M3 regresi (menu, checkout, kiosk, POS) | 137/137 | 0 |
| E2E penuh (R1): `CatalogTest`, `OrdersTest`, `OutletsTest`, `PaymentFlowTest`, `RoutesTest` | 35/35 (366 assertion) | 0 |

## Pekerjaan minimum menuju UAT outlet

Urut prioritas. Pekerjaan kode (1–3) baru dimulai setelah M4 disetujui.

| No | Pekerjaan | Jenis | Alasan |
|---|---|---|---|
| 1 | **Halaman pengaturan outlet sederhana**: nama, alamat, prefix nomor antrean, batas waktu bayar, status aktif | Kode (kecil) | **Dibangun** (commit WIP, menunggu review owner). Telepon belum |
| 2 | **Master meja per outlet** + checkout menolak nomor meja di luar daftar | Kode (kecil–sedang) | **Dibangun** (commit WIP, menunggu review owner). Migration aditif `2026_11_04_000001` sudah diterapkan di dev |
| 3 | Commit final M4 (setelah persetujuan) dan remote Git untuk deploy | Git | Staging butuh sumber kode yang disetujui |
| 4 | Pilih hosting Singapura dan buat Supabase staging Singapura | Owner | [STAGING_HOSTING_PLAN.md](STAGING_HOSTING_PLAN.md), [SUPABASE_STAGING_PLAN.md](SUPABASE_STAGING_PLAN.md) |
| 5 | Deploy staging + smoke test | Pengelola deploy | [LARAVEL_STAGING_RUNBOOK.md](LARAVEL_STAGING_RUNBOOK.md) |
| 6 | Data master asli (menu, harga, staf, meja, perangkat) | Owner/Manager | [MASTER_DATA_PREPARATION.md](MASTER_DATA_PREPARATION.md) |
| 7 | UAT outlet 24 skenario | Manager/Owner | [SUDAYA_OUTLET_UAT.md](SUDAYA_OUTLET_UAT.md) |

Kandidat setelah UAT (tidak menghalangi soft launch): tampilan nomor antrean untuk customer kiosk/layar antrean publik (EXT-012), halaman pesanan admin agar Owner bisa memberi approval, laporan/ekspor (M6), stok (M5), absensi (M7).

## Checklist UAT outlet (ditandatangani Manager/Owner)

| No | Skenario | Hasil | Paraf |
|---|---|---|---|
| 1 | Kasir membuka kas, membuat dan membayar order tunai, kembalian benar | | |
| 2 | Customer scan QR meja, memesan, membayar di kasir | | |
| 3 | Order lunas masuk KDS tanpa refresh | | |
| 4 | Barista menyelesaikan pesanan (Preparing → Ready → Completed) | | |
| 5 | Status di HP customer berubah sendiri | | |
| 6 | Produk sold out tidak bisa dipesan | | |
| 7 | Dua kasir membayar bersamaan: nomor antrean tidak sama | | |
| 8 | Internet terputus lalu kirim ulang: tidak ada order ganda | | |
| 9 | Tutup kas: uang fisik cocok dengan sistem | | |
| 10 | Staf tidak bisa membuka menu di luar perannya | | |
| 11 | Pembatalan order lunas disetujui Manager lain lalu refund tercatat | | |
| 12 | Struk tercetak di printer outlet | | |

Manager: ______________________ Tanggal: __________

Owner: ________________________ Tanggal: __________
