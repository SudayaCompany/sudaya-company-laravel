# Rencana UX-5 dan jalur go-live klien

Disusun 8 November 2026 setelah UX-4 disetujui owner dan di-push (`df80e00`). Keputusan owner 8 November tercatat di §7; hosting dibahas di §8. **Pembaruan 9 November: UX-5 inti selesai (Development Verified, commit lokal, belum di-push)**; hasil QA di `QA_CHECKPOINT.md` bagian UX-5.

## 1. Posisi sekarang

| Bagian | Status |
|---|---|
| M1–M4 (login, RBAC, katalog, order, POS, QR, kiosk, kas, KDS, batal, refund) | DONE — Development Verified |
| Pengaturan outlet + daftar meja | DONE — Development Verified |
| UX-0 rencana, UX-1 design system, UX-4 POS/kas/KDS | DONE, di GitHub (`main` = `df80e00`) |
| UX-5 dasbor Owner/Admin + ekspor menu | **DONE — Development Verified** (9 Nov, commit lokal, menunggu review owner) |
| UX-2 CMS halaman depan | Belum (spesifikasi ada) |
| M5 stok, M6 laporan, **M7 karyawan & absensi** | Ditunda sesuai keputusan owner 3 November |
| Hosting, Supabase production/staging, domain | **Belum ada — penghalang utama** |

## 2. Apa yang dibutuhkan agar klien bisa langsung memakai web

| Kebutuhan klien | Kondisi | Yang harus terjadi |
|---|---|---|
| **Membuka web dari HP/tablet outlet** | Aplikasi hanya berjalan di laptop development. Database dev ada di Eropa (eu-west-1) | Owner memilih dan membeli hosting Singapura, membuat project Supabase Singapura, menyiapkan domain. Langkah teknis siap di [STAGING_PREFLIGHT.md](STAGING_PREFLIGHT.md). **Tanpa ini, fitur apa pun belum bisa dipakai klien** |
| **Data produk** (menu, harga, foto, varian) | Fitur admin sudah lengkap (`/admin/products`, kategori, varian & tambahan, foto, habis per outlet) | Data asli dari klien. Diinput di staging untuk UAT lalu diulang di production (MASTER_DATA_PREPARATION). Usul: fitur impor/ekspor menu (§5) agar tidak mengetik dua kali |
| **Akun staf** (kasir, barista, manager) | Sudah ada (undang lewat menu Staf) | Daftar nama dan email staf dari klien |
| **Absensi karyawan** | **Belum ada di Laravel** (M7 ditunda). Tabel dan aturannya sudah ada di database; referensi Next.js sudah membangun modul ini (Epic 05) | Owner menyetujui M7 dan memilih metode absen (§4) |
| **Owner memantau usaha** | Selesai di UX-5: dasbor angka asli, Pesanan hari ini, Persetujuan, Kas (hanya-lihat) di area admin | Review owner |

## 3. UX-5: Dasbor dan pengalaman Owner/Admin

Lingkup inti untuk go-live:

| Bagian | Isi | Risiko |
|---|---|---|
| Dasbor dengan data asli | Penjualan lunas hari ini, transaksi, rata-rata, item terjual, pesanan menunggu bayar dan selesai, status sesi kas + selisih terakhir, approval menunggu, produk habis, 5 menu terlaris hari ini. Empty state bila belum ada transaksi. Tanpa grafik/angka contoh | Query agregat baru hanya-baca, scope outlet + tenant |
| Akses Owner (keputusan K5) | `/admin/orders` (daftar + detail hanya-baca), kotak masuk approval pembatalan (setujui/tolak), ringkasan sesi kas. Memakai izin yang sudah ada (`order.view`, `cash.view`, `approval.*`), **tanpa mengubah RBAC**. Owner tetap tidak bisa membuat pesanan atau menerima uang | Route + query baru, cek izin per outlet |
| Daftar admin konsisten | Pola satu: toolbar (cari + filter langsung), tabel yang jadi kartu di HP, paginasi. Untuk Menu, Staf, Log aktivitas (log: filter + paginasi server) | UI + query berfilter |
| Peran & izin | Nama izin bahasa Indonesia, header sticky | UI saja |

Bila M7 disetujui, menu admin bertambah grup **Karyawan & absensi**, dan dasbor menampilkan siapa yang sudah absen masuk hari ini.

**Kriteria terima:**
- Angka dasbor sama dengan hitungan test live.
- Owner dapat melihat pesanan dan menyetujui pembatalan, tetapi tidak bisa membuat pesanan atau menerima uang.
- Daftar admin memakai satu pola.
- Regresi M1–M4 + UX-1/UX-4 lolos.

**Estimasi:** 2–2,5 hari kerja.

**Status 9 November — selesai:**

| Bagian | Hasil |
|---|---|
| Dasbor | Omzet bersih, transaksi, rata-rata, item terjual, 5 menu terlaris, pesanan (menunggu bayar / antrean / selesai), kas (saldo sistem sesi terbuka, selisih sesi terakhir), "Perlu tindakan" (persetujuan, belum bayar, menu habis, kas belum dibuka). Dimuat sesudah halaman tampil, diperbarui otomatis tiap menit (berhenti saat tab tidak aktif) + tombol Perbarui. Bagian tampil sesuai izin peran |
| Area Owner | `/admin/orders` (daftar + detail + struk), `/admin/approvals` (setujui/tolak pembatalan), `/admin/cash` (hanya-lihat). Memakai ulang halaman kasir; bayar/batal/refund dan buka/tutup laci tetap hanya di Kasir. RBAC tidak diubah; peta rute bertambah `/admin/approvals` (`approval.view`) dan `/admin/cash` (`cash.view`) |
| Daftar admin | Menu: filter langsung (tanpa tombol Terapkan) + Hapus filter. Staf: cari nama/email. Log aktivitas: nama aksi bahasa Indonesia + filter kategori |

**Ditunda (bukan penghalang go-live):** paginasi server Log aktivitas (masih 100 terakhir), tabel yang berubah jadi kartu di HP, nama izin bahasa Indonesia + header sticky di Peran & izin.

## 4. M7-lite: Karyawan dan absensi (perlu persetujuan, karena M7 ditunda)

Port dari referensi Next.js (Epic 05), yang sudah punya:
- engine absensi ±1.350 baris;
- data karyawan;
- pengaturan;
- absen masuk/pulang dengan geofence;
- koreksi absen;
- tugas luar dengan bukti;
- laporan + ekspor CSV;
- ±850 baris test.

Tabel database sudah ada (`employees`, `attendances`, `attendance_settings`, `attendance_corrections`, `outside_duty_requests`, `face_enrollments`, `face_verification_sessions`); bucket bukti privat `attendance-evidence` dibuat oleh skrip setup Storage (keberadaannya di dev diverifikasi saat implementasi).

### Masalah yang harus diputuskan: metode absen harian

PRD §8–9 meminta **pengenalan wajah**. Penyedia wajah (EXT-003) belum dipilih. Di referensi Next.js, tanpa penyedia, absen hanya bisa dicatat lewat koreksi manual yang disetujui manager. Itu **tidak praktis untuk absen harian**.

| Opsi | Cara kerja | Kelebihan | Kekurangan | Waktu |
|---|---|---|---|---|
| **A. GPS + foto selfie** (rekomendasi sementara) | Staf membuka menu Absen di HP → kamera ambil selfie → GPS dicek terhadap radius outlet → tersimpan dengan jarak, akurasi, foto (bucket privat), perangkat, IP. Manager bisa melihat foto bila ragu | Cepat, tanpa biaya penyedia, ada bukti visual, memakai kolom yang sudah ada | Tidak ada pencocokan wajah otomatis; titip absen masih mungkin tetapi terekam fotonya | ±3 hari |
| B. GPS saja | Seperti A tanpa foto | Paling sederhana | Mudah dititipkan; bukti lemah | ±2,5 hari |
| C. Tablet absen di outlet | Satu tablet tetap di outlet; staf memilih nama + PIN | Tanpa GPS | Mudah dititipkan; butuh perangkat khusus | ±2,5 hari |
| D. Pengenalan wajah (sesuai PRD) | Penyedia resmi (mis. Verihubs, VIDA, AWS Rekognition) | Paling kuat | Kontrak dan biaya penyedia, persetujuan data biometrik (UU PDP); berminggu-minggu | Setelah penyedia dipilih |

Rekomendasi: **A sekarang, D menyusul** setelah owner memilih penyedia. Data opsi A tetap kompatibel; kolom skor wajah dibiarkan kosong.

### Lingkup M7-lite

| Bagian | Isi |
|---|---|
| Karyawan | Daftar, tambah, ubah, nonaktifkan; terhubung ke akun staf yang sudah ada; kode karyawan, jabatan, outlet, tanggal masuk |
| Pengaturan absen | Per outlet: koordinat (diambil dari lokasi perangkat saat Super Admin berdiri di outlet, atau diketik; tanpa layanan peta pihak ketiga agar CSP tidak dilonggarkan), radius (default PRD 5 m, bisa diubah), batas akurasi GPS |
| Absen staf | Masuk/pulang dari HP (menu Staf): kamera + GPS, pesan jelas bila di luar radius atau akurasi buruk; jam kerja dihitung otomatis |
| Koreksi | Staf mengajukan koreksi (lupa absen/pulang) → manager menyetujui |
| Tugas luar | Pengajuan dengan alasan + bukti foto → disetujui manager; absen di luar radius saat tugas luar disetujui |
| Laporan | Rekap per karyawan per periode (hadir, jam kerja, koreksi), ekspor CSV |
| Privasi | Foto hanya di bucket privat, dibuka lewat server berizin; kebijakan retensi (mis. 90 hari) diputuskan owner; persetujuan tertulis staf (versi kebijakan sudah ada di schema) |

**Perubahan schema:** satu migration aditif untuk menambah nilai metode `gps_selfie` (atau `gps`) pada enum `attendance_method`. Tabel lain tidak diubah.

**Di luar lingkup:** pengenalan wajah dan liveness (opsi D), jadwal shift dan aturan telat (belum ada aturannya di PRD maupun data klien), penggajian.

**Kriteria terima:**
- Staf bisa absen masuk/pulang di outlet (dalam radius) dari HP.
- Absen di luar radius ditolak dengan pesan jelas.
- Foto hanya bisa dibuka manager/admin berizin.
- Koreksi dan tugas luar butuh persetujuan manager lain.
- Laporan + CSV sesuai data test live.
- Isolasi tenant dan izin dicek di server; audit untuk semua aksi.

**Estimasi:** ±3 hari kerja termasuk test live, E2E, dan browser.

## 5. Data produk: input dari web + ekspor ke spreadsheet

Keputusan owner (8 November): **semua data diinput dari web** (menu admin yang sudah ada), dan disediakan **ekspor ke spreadsheet** bila dibutuhkan. Impor CSV tidak dibuat.

| Bagian | Isi | Waktu |
|---|---|---|
| Input | `/admin/products`, kategori, varian & tambahan, foto, habis per outlet (sudah ada) | 0 |
| Ekspor menu (**selesai 9 Nov**, bagian UX-5) | Tombol **Ekspor ke spreadsheet** di Menu → file `.xlsx` (langsung terbuka di Excel/Google Sheets): kategori, nama, SKU, deskripsi, harga dasar, status, varian & tambahan, ketersediaan + harga khusus per outlet. Hanya pengguna dengan izin melihat menu; tanpa kolom internal (id, path foto). Dibuat sebagai XLSX (bukan CSV) agar angka tetap angka, huruf Indonesia aman, dan teks tidak pernah dijalankan sebagai rumus; tanpa dependency baru | selesai |

Tidak ada harga atau produk karangan.

## 6. Urutan yang disarankan (target pemakaian klien secepatnya)

| Urutan | Pekerjaan | Pelaksana | Waktu |
|---|---|---|---|
| Paralel, mulai sekarang | Pilih + beli hosting Singapura, buat Supabase Singapura, domain; kumpulkan data menu, staf, koordinat outlet, foto | **Owner/klien** | — |
| 1 | UX-5 inti: dasbor data asli + akses Owner (pesanan, kas, approval) | Developer | **selesai 9 Nov** |
| 2 | M7-lite absensi (setelah keputusan metode) | Developer | ±3 hari |
| 3 | Ekspor menu | Developer | **selesai bersama UX-5** (impor tidak dibuat, keputusan G7) |
| 4 | Deploy staging + smoke test + UAT outlet (STAGING_PREFLIGHT §5) | Developer + owner | 1–2 hari |
| 5 | Production + data asli + pelatihan staf | Developer + owner | 1 hari |
| Setelah go-live | UX-2 CMS halaman depan, UX-3/UX-6 penyempurnaan, M5 stok, M6 laporan | Developer | — |

Perkiraan total kerja developer sampai siap UAT: **±6,5–7,5 hari kerja**, berjalan paralel dengan persiapan hosting dan data oleh owner. Ini perkiraan kasar, bukan komitmen.

## 7. Keputusan yang dibutuhkan dari owner

| No | Keputusan | Status |
|---|---|---|
| G1 | Setujui UX-5 inti (§3) | **Diputuskan 8 Nov: UX-5 dikerjakan lebih dulu** |
| G2 | Buka M7-lite absensi | **Diputuskan 8 Nov: ya, sesudah UX-5** |
| G3 | Metode absen harian (§4) | **Diputuskan 8 Nov: opsi A, GPS + foto selfie**; wajah menyusul |
| G4 | Radius geofence awal dan batas akurasi GPS | PRD 5 m tetap jadi nilai awal; diuji di outlet lalu disetel (umumnya 20–50 m) |
| G5 | Retensi foto absen dan bukti tugas luar | 90 hari |
| G6 | Migration aditif enum metode absen (dev, lalu staging/production saat deploy) | Ya |
| G7 | Data produk (§5) | **Diputuskan 8 Nov: input dari web + ekspor spreadsheet** (tanpa impor) |
| G8 | Hosting, Supabase Singapura, domain (penghalang utama) | Owner menanyakan DomaiNesia (domain sudah di sana); lihat §8 |

## 8. Hosting di DomaiNesia (pertanyaan owner 8 November)

Domain yang sudah ada di DomaiNesia **bisa dipakai** dengan hosting mana pun: cukup arahkan DNS (record A) ke server aplikasi.

Untuk hosting aplikasinya, DomaiNesia punya dua jenis layanan yang relevan:

| Layanan | Kecocokan dengan Sudaya | Catatan |
|---|---|---|
| **Cloud VPS** (rekomendasi) | **Cocok.** Akses root penuh, jadi Nginx + PHP-FPM 8.3/8.4 + ekstensi `pdo_pgsql` + Composer + sertifikat HTTPS bisa dipasang sendiri. Lokasi server bisa dipilih **Jakarta atau Singapura**. Ini sesuai rekomendasi STAGING_PREFLIGHT §8 no. 1 (Nginx + PHP-FPM memutus HTTPS sendiri, tanpa perubahan kode) | Pilih **Singapura**, satu region dengan Supabase Singapura, karena setiap halaman menjalankan beberapa query database. Butuh orang yang merawat server (update keamanan, firewall, perpanjangan sertifikat otomatis). Paket 2 GB RAM cukup untuk satu outlet. Harga di situs (Lite mulai ±Rp43 ribu/bulan, Turbo mulai ±Rp80 ribu/bulan saat promo) harus dicek ulang saat order |
| Shared hosting cPanel (Nimbus) | **Belum pasti.** DomaiNesia menyebut dukungan PHP 8.1–8.4, SSH di sebagian paket, dan PostgreSQL lokal. Yang belum pasti: ekstensi `pdo_pgsql` aktif, koneksi keluar ke Supabase (port 5432/6543), dan jumlah proses PHP bersamaan untuk kasir + KDS + pelanggan | Hanya bila lolos uji kelayakan sebelum membayar penuh: `php -v` ≥ 8.3, `php -m` memuat `pdo_pgsql`, koneksi ke pooler Supabase dari server berhasil, SSH + Composer tersedia |

**Rekomendasi:** DomaiNesia **Cloud VPS lokasi Singapura** (2 GB), domain tetap di DomaiNesia, database dan login di **Supabase Singapura**. Supabase paket gratis berhenti sendiri bila tidak aktif dan tanpa backup harian, sehingga production sebaiknya memakai paket berbayar. Itu keputusan biaya owner.

Yang dibutuhkan dari owner untuk deploy:
1. Order Cloud VPS (Ubuntu LTS, lokasi Singapura) dan beri akses SSH ke pengelola deploy, lewat kunci SSH, bukan password di chat.
2. Buat project Supabase lokasi Singapura (`ap-southeast-1`).
3. Tentukan subdomain staging (mis. `staging.<domain>`) dan domain production.

Langkah teknis dan verifikasinya ada di [STAGING_PREFLIGHT.md](STAGING_PREFLIGHT.md) §5.

Sumber (dicek 8 November 2026):
- [Cloud VPS Lite](https://www.domainesia.com/cloud-vps-lite/)
- [Cloud VPS](https://www.domainesia.com/cloud-vps/)
- [Apa itu Cloud VPS DomaiNesia](https://www.domainesia.com/panduan/apa-itu-virtual-server/)
- [Shared hosting untuk Laravel](https://www.domainesia.com/tips/shared-hosting-untuk-laravel/)
- [Teknologi/datacenter](https://www.domainesia.com/teknologi/)
