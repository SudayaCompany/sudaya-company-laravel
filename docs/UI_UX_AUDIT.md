# Audit UI/UX Sudaya Company (UX-0)

Disusun 6 November 2026 dari kode commit `e52b3e9`, screenshot QA browser 4–5 November (HP 375 px, tablet 768/1024 px, desktop 1280 px), PRD §5, §35–41, §61, §86–89, dan dokumen QA/go-live. **Audit saja; belum ada perubahan kode.** Rujukan file memakai path relatif `resources/js/` kecuali disebut lain.

Prioritas: **P0** = memperlambat transaksi, memicu salah operasi, atau memutus akses role yang seharusnya ada; **P1** = nilai tinggi, risiko rendah; **P2** = penyempurnaan. Risiko perubahan: **UI** (tampilan saja), **Props** (data tambahan dari server tanpa mengubah aturan), **Server** (query/route baru), **Schema** (migration).

## 1. Ringkasan

Fondasi teknis kuat: alur transaksi lengkap, realtime, idempotensi, dan aturan bisnis teruji (unit 142, live 113, E2E 35, browser 291 cek). Masalah utamanya ada di **konsistensi dan ergonomi**, bukan di logika:

1. **Komponen dasar terlalu kecil untuk layar sentuh.** Tombol bawaan 32 px (`components/ui/button.tsx:21-32`); halaman menimpa tinggi sendiri-sendiri. Tombol −/+/hapus di keranjang POS, Setujui/Tolak, dan aksi tabel admin berukuran 24–28 px.
2. **Satu tugas, banyak pola.** Admin punya 4 cara membuat data (halaman terpisah, kartu inline, baris inline, input massal), 3 cara menonaktifkan (dengan konfirmasi, tanpa konfirmasi, switch langsung), dan 4 hook mutasi berbeda.
3. **Navigasi terputus antar-area.** Admin dan POS tidak saling menaut. Manager harus mengetik URL, dan **Owner tidak bisa membuka pesanan, kas, maupun approval** walau punya izinnya, karena semua halaman itu ada di bawah `/pos` yang mensyaratkan `order.create`.
4. **Dashboard admin kosong.** Hanya kartu akun dan outlet. Data untuk penjualan hari ini, transaksi, order pending, dan sesi kas sudah ada di database.
5. **Bahasa campur dan istilah teknis tampil ke staf:** "Start Preparing", "Sold out", "Walk-in", "Channel", "Access Denied"; slug, kode permission, kode role, dan kode audit mentah.
6. **Tidak ada pengelolaan konten website.** Landing memakai teks di kode; tidak ada logo resmi atau foto Sudaya di repo, dan tidak ada tempat untuk mengunggahnya.

## 2. Inventaris halaman

| Area | Route | Halaman | Pengguna | Kondisi |
|---|---|---|---|---|
| Customer | `/` | `pages/home.tsx` | Publik | Redesign 5 Nov; data deferred; copy di kode |
| Customer | `/order?outlet=&table=` | `pages/order/index.tsx` → `components/storefront/storefront.tsx` | Publik, QR meja/kasir | Redesign 5 Nov (pencarian, chip kategori) |
| Customer | (sheet di storefront) | Keranjang, checkout | Publik | Bottom sheet |
| Customer | `/order/status/{order}` | `pages/order/status.tsx` | Pemesan | Realtime |
| Customer | `/kiosk?outlet=` | `pages/kiosk/index.tsx` → storefront mode kiosk | Kiosk | Landscape, reset 45 detik |
| Auth | `/login` | `pages/auth/login.tsx` | Staf | Split screen |
| Auth | `/auth/set-password` | `pages/auth/set-password.tsx` | Staf baru | |
| Auth | (render) | `pages/pending.tsx`, `pages/errors/forbidden.tsx`, `not-found.tsx` | Semua | |
| Staf | `/staff` | `pages/staff/home.tsx` | Staf | 4 dari 6 tile "Segera hadir" |
| Staf | `/pos` | `pages/pos/index.tsx` | Kasir, Barista, Manager | Keranjang tetap ≥ 1024 px |
| Staf | `/pos/orders`, `/pos/orders/{id}` | `pages/pos/orders/index.tsx`, `show.tsx` | Kasir, Barista, Manager | Approval/refund di detail |
| Staf | `/pos/orders/{id}/receipt` | `pages/pos/orders/receipt.tsx` | Kasir | 80 mm |
| Staf | `/pos/cash` | `pages/pos/cash.tsx` | Kasir, Manager | Buka, kas masuk/keluar, tutup |
| Staf | `/kds` | `pages/kds/index.tsx` | Barista, Manager, Owner | Realtime |
| Admin | `/admin` | `pages/admin/dashboard.tsx` | Owner, Manager, Admin, Super Admin | Kosong dari data operasional |
| Admin | `/admin/products`, `/create`, `/{id}/edit` | `pages/admin/products/index.tsx`, `form.tsx` | Super Admin, Owner, Admin, Manager (edit) | Satu-satunya daftar berpaginasi |
| Admin | `/admin/categories` | `pages/admin/categories.tsx` | sama | Edit inline per baris |
| Admin | `/admin/modifiers` | `pages/admin/modifiers.tsx` | sama | Kartu expand |
| Admin | `/admin/outlets`, `/{id}` | `pages/admin/outlets/index.tsx`, `edit.tsx` | Super Admin | Pengaturan + meja |
| Admin | `/admin/users` | `pages/admin/users.tsx` | Super Admin | Undang + tabel |
| Admin | `/admin/roles` | `pages/admin/roles.tsx` | Super Admin | Matriks permission |
| Admin | `/admin/audit` | `pages/admin/audit.tsx` | Super Admin, Owner | 100 entri terbaru |
| Layout | — | `layouts/admin-layout.tsx`, `layouts/pos-layout.tsx` | | Dua shell terpisah |

Dipetakan di `app/Support/Access/RouteAccess.php` tetapi **belum ada route/halaman** (jangan ditautkan sampai dibuat): `/admin/orders`, `/admin/reports`, `/admin/inventory*`, `/admin/employees`, `/admin/attendance*`, `/admin/outside-duty`, `/staff/attendance`, `/staff/face-enrollment`, `/staff/outside-duty`.

## 3. Audit per halaman

Format: **Sekarang** · **Masalah** · **Rekomendasi** · **Prioritas / Risiko** · **Kriteria terima**.

### 3.1 Landing `/` (`pages/home.tsx`, `app/Http/Controllers/HomeController.php`)
- **Sekarang:** hero gelap tanpa foto (ilustrasi cangkir bila produk tidak berfoto), cara pesan 4 langkah, menu pilihan dari data asli, kartu lokasi, footer. Semua teks di kode.
- **Masalah:**
  - Tidak ada foto Sudaya asli, seksi cerita/tentang, galeri, jam buka, peta, kontak, atau tautan sosial.
  - Konten hanya bisa diubah developer.
  - Telepon, koordinat, dan `operating_hours` outlet sudah ada di tabel `outlets`, tetapi tidak pernah diisi lewat UI.
- **Rekomendasi:**
  - Hero foto penuh dengan overlay; seksi Tentang, Menu unggulan, Galeri, Cara pesan, Kunjungi kami; footer lengkap.
  - Semua konten dari CMS ([ADMIN_CMS_SPEC.md](ADMIN_CMS_SPEC.md)), dengan fallback ke copy sekarang bila CMS kosong.
- **Prioritas / Risiko:** P1 / Schema (tabel konten) + Server.
- **Kriteria terima:**
  - Tanpa data CMS, halaman tampil seperti sekarang.
  - Dengan data CMS, semua seksi tampil dari data terbit.
  - LCP hero ≤ 2,5 detik di staging (diukur, bukan di laptop).
  - Tidak ada harga atau ketersediaan yang disalin ke tabel konten.

### 3.2 Menu customer `/order` (`components/storefront/storefront.tsx`)
- **Sekarang:** judul Fraunces, pencarian, chip kategori aktif, kartu produk, bottom sheet produk, bar keranjang mengambang, checkout di sheet. QRIS ditampilkan nonaktif ("Belum tersedia").
- **Masalah:**
  - Opsi pembayaran yang tidak tersedia tetap tampil.
  - Label tipe pesanan berbahasa Inggris.
  - Belum ada pintasan "pesanan saya" untuk membuka status pesanan sebelumnya di perangkat yang sama.
  - Tombol keranjang −/+ `icon-sm` (28 px).
- **Rekomendasi:**
  - Sembunyikan metode yang belum aktif; ringkasan cara bayar di checkout.
  - Label Indonesia.
  - Tautan "Lihat pesanan terakhir" (cookie token yang sudah ada).
  - Target sentuh 44 px.
- **Prioritas / Risiko:** P1 / UI (+ Props kecil untuk pesanan terakhir).
- **Kriteria terima:**
  - Probe M3 dan redesign tetap lolos setelah label diperbarui bersama test.
  - Semua target ≥ 44 px di 360 px.
  - Checkout tidak bisa terkirim dua kali (sudah ada; dipertahankan).

### 3.3 Status pesanan `/order/status/{id}`
- **Sekarang:** kartu status besar, langkah progres, hitung mundur bayar, detail item, realtime.
- **Masalah:**
  - Label status berbeda dari KDS dan POS (Diterima/Dibuat/Siap vs New/Preparing/Ready vs Antre/Dibuat/Siap).
  - Saat siap tidak ada sinyal selain teks: tidak ada getar atau suara (opsional, butuh interaksi pengguna).
- **Rekomendasi:**
  - Peta status tunggal ([UI_UX_DESIGN_SYSTEM.md](UI_UX_DESIGN_SYSTEM.md) §2.2).
  - Banner "Siap diambil" penuh warna.
  - Getar opsional bila browser mengizinkan.
- **Prioritas / Risiko:** P1 / UI. **Kriteria terima:** label sama di 4 layar; realtime tetap `live`.

### 3.4 Kiosk `/kiosk`
- **Sekarang:**
  - Judul besar, kartu 2–4 kolom.
  - Tanpa pencarian, tanpa tautan ke `/` atau `/login` (terverifikasi probe).
  - Konfirmasi kode referensi, reset 45 detik.
- **Masalah:**
  - Keranjang hanya lewat bar bawah; di landscape tidak terlihat bersamaan dengan menu.
  - Konfirmasi tidak menjelaskan langkah fisik ("tunjukkan kode ini ke kasir"), dan tidak ada hitung mundur reset yang terlihat.
  - Tidak ada layar mulai ("Sentuh untuk memesan") saat kiosk diam.
- **Rekomendasi:**
  - Panel keranjang samping di ≥ 1024 px.
  - Tombol 64 px.
  - Layar sambut dan hitung mundur reset terlihat.
- **Prioritas / Risiko:** P1 / UI.
- **Kriteria terima:**
  - Satu pesanan 2 item bisa selesai dengan ≤ 8 sentuhan.
  - Kode referensi ≥ 48 px.
  - Tidak ada data pemesan sebelumnya setelah reset.

### 3.5 Login, set password, error
- **Sekarang:** login split-screen rapi; error tampil sebagai toast dan inline (dobel). Set password mengosongkan kedua field bila error. Halaman 403 berjudul "Access Denied". Halaman 404/pending menaut ke `/` (halaman customer).
- **Masalah:**
  - Klaim di login menyebut "stok dan laporan", padahal modul itu belum ada.
  - Tidak ada tautan "Lupa password" (reset sekarang lewat admin).
  - Tautan kembali staf salah tujuan.
- **Rekomendasi:**
  - Copy sesuai fitur yang ada.
  - Satu tempat error.
  - Judul Indonesia.
  - Tombol kembali sesuai area (staf ke `/staff`, publik ke `/`).
  - "Lupa password? Hubungi admin" sampai alur reset mandiri dibuat.
- **Prioritas / Risiko:** P1 / UI. **Kriteria terima:** tidak ada teks Inggris; error muncul sekali.

### 3.6 Beranda staf `/staff`
- **Sekarang:** kartu sapaan dan 6 tile, 4 di antaranya "Segera hadir" (absensi, tugas luar, wajah, stok).
- **Masalah:**
  - Mayoritas tile mati.
  - Tidak ada pintasan Pesanan, Kas, atau Admin bagi yang berhak.
  - Tidak ada status operasional (kas terbuka? order menunggu?).
- **Rekomendasi:**
  - Tile hanya untuk fitur yang ada: Kasir, Pesanan, Kas, KDS, Admin.
  - Ringkasan kecil: kas terbuka/tutup, jumlah order menunggu bayar.
  - Fitur mendatang disembunyikan, bukan dipamerkan.
- **Prioritas / Risiko:** P1 / Props (ringkasan dari query yang sudah ada). **Kriteria terima:** tidak ada tile "Segera hadir"; Manager mencapai admin dalam 1 ketukan.

### 3.7 POS `/pos` (`pages/pos/index.tsx`)
- **Sekarang:**
  - Keranjang tetap di ≥ 1024 px; sheet di bawahnya.
  - Pencarian dan chip kategori.
  - Uang Pas/50rb/100rb, tombol "Bayar tunai & proses", dialog hasil dengan nomor antrean 36 px.
  - Idempotensi dan anti klik ganda sudah benar.
- **Masalah:**
  - ±7 ketukan untuk 2 item plus bayar pas. Dialog modifier tetap terbuka untuk produk tanpa modifier, jadi ada 1 ketukan ekstra per item.
  - Tombol "Tambah" di dialog ikut tergulir pada daftar modifier panjang.
  - Kembalian di keranjang hanya `text-sm`.
  - Uang cepat tidak menyesuaikan total (tidak ada 10rb/20rb; total > 100rb hanya "Pas").
  - Input uang tanpa pemisah ribuan.
  - Target sentuh 28–36 px (−/+/hapus, Kosongkan, chip, tipe pesanan, stepper).
  - "Simpan, bayar nanti" sangat dekat dengan tombol bayar.
  - Tidak ada indikator koneksi maupun status kas di header.
  - Istilah Inggris (Sold out, Dine In, Takeaway).
- **Rekomendasi:**
  - Tambah langsung untuk produk tanpa grup modifier.
  - Footer "Tambah" sticky.
  - Kembalian 28–36 px.
  - Uang cepat dinamis (pas, pembulatan ke atas, 20/50/100rb).
  - Input berformat.
  - Target 48 px.
  - Pisahkan "Simpan, bayar nanti" sebagai aksi sekunder berjarak.
  - Chip status kas dan koneksi di top bar.
- **Prioritas / Risiko:** **P0** / UI (harga, idempotensi, dan validasi uang tetap di server).
- **Kriteria terima:**
  - 2 item tanpa modifier plus bayar pas ≤ 5 ketukan.
  - Semua target ≥ 44 px.
  - Kembalian terbaca dari 1 meter.
  - Probe M4 73/73 tetap lolos (label diperbarui bersama).

### 3.8 Daftar & detail pesanan `/pos/orders`
- **Sekarang:** 4 filter chip, cari kode/nomor/nama (Enter), kartu baris, badge pembayaran + status. Detail dengan aksi bayar, batal, approval, dan refund.
- **Masalah:**
  - Badge dobel untuk order belum dibayar.
  - Chip tanpa jumlah.
  - Cari hanya dengan Enter.
  - Di < 1024 px aksi turun ke bawah, sehingga kasir harus menggulir untuk konfirmasi bayar.
  - Setelah bayar dari detail, kembalian hanya tampil di toast.
  - Setujui/Tolak 28 px tanpa konfirmasi dan tanpa waktu pengajuan.
  - Refund tanpa peringatan bila kas belum dibuka.
  - Riwayat menampilkan kode mentah `payment.refunded`.
  - "Channel", "Customer" dalam bahasa Inggris.
- **Rekomendasi:**
  - Satu badge sesuai peta status; jumlah per tab.
  - Cari langsung.
  - Panel aksi di atas detail pada layar kecil.
  - Hasil bayar dalam dialog besar seperti POS.
  - Konfirmasi approval dengan waktu pengajuan.
  - Peringatan kas tertutup.
  - Label riwayat lengkap.
- **Prioritas / Risiko:** **P0** (kembalian & approval) / UI + Props kecil (jumlah per tab).
- **Kriteria terima:** konfirmasi bayar dari detail menampilkan nomor antrean dan kembalian ≥ 28 px; tidak ada kode mentah.

### 3.9 Struk
- **Sekarang:** 80 mm, tinggi mengikuti isi, label DIREFUND/DIBATALKAN.
- **Masalah:**
  - Tidak ada alamat/kontak outlet.
  - "Customer:" berbahasa Inggris.
  - Harga modifier tanpa tanda "+".
  - Cetak butuh 2 klik plus tab baru.
  - Kertas 58 mm terpotong.
- **Rekomendasi:**
  - Alamat dan telepon dari data outlet.
  - Opsi lebar 58/80 mm per outlet (nanti, saat printer diketahui).
  - Tombol cetak langsung dari dialog hasil POS.
- **Prioritas / Risiko:** P1 / UI + Props (alamat outlet). **Kriteria terima:** PDF 80 mm tetap 1 halaman; data outlet tampil bila terisi.

### 3.10 Sesi kas `/pos/cash`
- **Sekarang:** ringkasan modal, penjualan, kas masuk/keluar, refund, saldo seharusnya; dialog tutup dengan selisih dan alasan; riwayat.
- **Masalah:**
  - Angka penting kecil (saldo `text-base`).
  - Saldo seharusnya ditampilkan sebelum menghitung fisik, sehingga hitungan bisa bias.
  - Selisih tanpa label lebih/kurang.
  - Minimal 3 karakter alasan tidak diberitahukan; tombol hanya nonaktif.
  - Tombol Kas masuk/keluar/Tutup sesi 32 px berdempetan.
  - Riwayat tanpa nama penutup.
- **Rekomendasi:**
  - Kartu angka besar.
  - Tutup kas dua langkah: hitung uang fisik dulu, baru tampilkan perbandingan.
  - Label "Lebih Rp …" / "Kurang Rp …".
  - Petunjuk minimal alasan.
  - Tombol 44 px dengan jarak; "Tutup sesi" terpisah.
  - Nama penutup di riwayat (Props).
- **Prioritas / Risiko:** **P0** / UI + Props. **Ledger kas dan aturan selisih tidak berubah.**
- **Kriteria terima:** test live kas tetap lolos; kasir dapat menutup kas tanpa melihat saldo sistem lebih dulu.

### 3.11 KDS `/kds`
- **Sekarang:** 4 kolom di ≥ 1280 px, 2 kolom di 768–1279 px; timer mm:ss sejak bayar (kuning 5 menit, merah 10 menit); tombol aksi 48 px; realtime.
- **Masalah:**
  - Di tablet landscape 1024 px hanya 2 kolom (Siap dan Selesai di bawah lipatan).
  - Nomor antrean hanya 24 px.
  - Modifier abu kecil (14 px); jumlah ditulis di belakang nama.
  - Judul kolom dan tombol berbahasa Inggris.
  - Kolom Selesai memakan seperempat layar.
  - Tidak ada label "Terlambat", bunyi order baru, atau sumber order (QR/POS/Kiosk); props `preparingAt`, `readyAt`, `channel` sudah dikirim tapi tidak dipakai.
  - Indikator koneksi kecil dan berbahasa teknis.
  - Header POS lengkap (termasuk Keluar) ada di tablet barista.
- **Rekomendasi:**
  - 3 kolom aktif (Antre, Dibuat, Siap) di ≥ 1024 px; Selesai jadi strip ringkas yang bisa dibuka.
  - Nomor 40–48 px; "2× Es Kopi Susu" dengan jumlah tebal di depan; modifier 16 px kontras tinggi.
  - Label Indonesia; label "Terlambat" plus warna; badge sumber order.
  - Bunyi opsional (diaktifkan barista sekali per sesi).
  - Banner koneksi terputus.
  - Header ringkas mode layar penuh.
- **Prioritas / Risiko:** **P0** / UI. **State machine, `from` guard, dan izin mundur tidak berubah.**
- **Kriteria terima:**
  - 3 kolom terlihat tanpa scroll di 1024 × 768.
  - Nomor terbaca dari 2 meter.
  - Probe M4 lolos (label diperbarui bersama test).

### 3.12 Dashboard admin `/admin`
- **Sekarang:** kartu akun dan kartu outlet pertama (`outlets[0]`, tanpa pemilih). Tombol keluar khusus HP hanya di halaman ini.
- **Masalah:** tidak ada data operasional (PRD §61).
- **Rekomendasi:**
  - Kartu angka dari data asli:
    - penjualan lunas hari ini;
    - jumlah transaksi;
    - rata-rata per transaksi;
    - item terjual;
    - order menunggu bayar;
    - order selesai;
    - status sesi kas dan selisih terakhir;
    - approval menunggu;
    - produk habis.
  - Daftar 5 produk terlaris hari ini bila ada transaksi.
  - Empty state bila belum ada transaksi.
  - Tanpa grafik sampai datanya cukup.
- **Prioritas / Risiko:** **P0** (Owner/Manager butuh ringkasan saat soft launch) / Server (query agregat baru, hanya-baca, dengan scope outlet dan tenant).
- **Kriteria terima:**
  - Angka sama dengan hitungan manual dari test live.
  - Query ≤ 6 per muat.
  - Tidak ada angka contoh.

### 3.13 Produk (`products/index.tsx`, `form.tsx`)
- **Sekarang:** tabel dengan 6–7 filter dan tombol "Terapkan", paginasi server 20, foto, status, ketersediaan per outlet. Form dua kolom dengan unggah foto (validasi tipe dan 2 MB, pratinjau).
- **Masalah:**
  - Filter perlu klik "Terapkan" dan tidak ada reset.
  - Slug tampil di daftar dan form.
  - "Sold out".
  - Ikon aksi 24–28 px tanpa label (ikon Power ambigu).
  - Tabel 7 kolom tanpa tampilan HP.
  - Error validasi server tampil dua kali (banner dan toast) dan tidak di dekat field.
  - Checkbox modifier memakai `input` native, beda dengan halaman role.
  - Form edit kehilangan nama brand.
- **Rekomendasi:**
  - Toolbar dengan filter langsung dan reset.
  - Slug dipindah ke "Pengaturan lanjutan" (terlipat).
  - Menu aksi baris berlabel.
  - Kartu di HP.
  - Error per field.
  - Komponen `ImageUpload` bersama (dipakai juga CMS).
- **Prioritas / Risiko:** P1 / UI (+ Server kecil untuk error per field). **Kriteria terima:** E2E `CatalogTest` dan probe M2 lolos; semua aksi ≥ 40 px dan berlabel.

### 3.14 Kategori
- **Sekarang:** buat inline; edit inline per baris dengan tombol simpan ikon; switch aktif menyimpan langsung.
- **Masalah:**
  - Menekan switch aktif ikut menyimpan perubahan nama yang belum disimpan.
  - Konfirmasi hapus tetap menawarkan "Hapus" untuk kategori berisi produk, walau teksnya bilang tidak bisa.
  - Urutan diatur lewat angka.
  - Label hanya `aria-label` di HP.
- **Rekomendasi:**
  - Edit lewat sheet/dialog yang sama dengan modifier.
  - Tombol hapus nonaktif dengan penjelasan bila berisi produk.
  - Urutan naik/turun.
- **Prioritas / Risiko:** P1 / UI. **Kriteria terima:** tidak ada penyimpanan tak sengaja; label terlihat.

### 3.15 Modifier
- **Sekarang:** kartu per grup, edit expand, template.
- **Masalah:**
  - Beberapa input tanpa nama aksesibel.
  - Template berbahasa Inggris ("Size", "Sugar Level").
  - Tidak ada empty state.
  - Hapus tanpa status loading.
- **Rekomendasi:** pola sheet/dialog yang sama; label lengkap; template Indonesia ("Ukuran", "Tingkat gula").
- **Prioritas / Risiko:** P2 / UI. **Kriteria terima:** semua input berlabel; empty state ada.

### 3.16 Outlet & meja
- **Sekarang:** daftar outlet; form pengaturan; meja ditambah massal (dipisah koma), switch aktif.
- **Masalah:**
  - Meja dinonaktifkan tanpa konfirmasi, padahal outlet dikonfirmasi.
  - Tidak ada cetak/unduh QR meja; hanya pola URL.
  - Telepon, jam buka, dan tautan peta belum bisa diisi.
  - Breadcrumb buatan sendiri.
- **Rekomendasi:**
  - Tab "Pengaturan / Meja & QR / Jam buka & kontak".
  - QR per meja bisa diunduh/dicetak. Dibuat di browser tanpa layanan luar; butuh pustaka QR kecil, persetujuan dependency.
  - Konfirmasi nonaktif meja.
- **Prioritas / Risiko:** P1 / UI + Server kecil (telepon dan jam buka memakai kolom yang sudah ada; koordinat tidak disentuh karena dipakai geofence absensi). **Kriteria terima:** QR hasil unduhan membuka `/order?outlet=…&table=…` yang tervalidasi.

### 3.17 Pengguna
- **Sekarang:** form undang selalu di atas, tabel semua pengguna, pengatur role per baris.
- **Masalah:**
  - Nonaktifkan akun, cabut role, dan reset password tanpa konfirmasi.
  - Tombol cabut role ±16 px.
  - Default undangan role pertama + "Semua outlet" (cakupan terluas) berisiko memberi akses berlebih.
  - Teks menyebut "Supabase".
  - Tidak ada pencarian.
  - Tabel meluber di HP.
- **Rekomendasi:**
  - Daftar dulu; "Undang staf" sebagai tombol yang membuka sheet.
  - Role tanpa default (wajib dipilih).
  - Konfirmasi untuk tiga aksi tersebut.
  - Detail pengguna di sheet.
  - Pencarian.
- **Prioritas / Risiko:** **P0** (salah klik menonaktifkan staf saat jam buka) / UI. **Aturan anti eskalasi tidak berubah.** **Kriteria terima:** setiap aksi akses dikonfirmasi; live `AccessControlTest` lolos.

### 3.18 Role & permission
- **Sekarang:** matriks ±41 permission × role, kode mentah, simpan per klik.
- **Masalah:**
  - Kode mentah dan grup berbahasa Inggris.
  - Checkbox nonaktif tanpa alasan.
  - Header dan kolom tidak sticky.
- **Rekomendasi:**
  - Nama permission dalam bahasa Indonesia (deskripsi sudah ada di `Rbac::PERMISSIONS`), kode sebagai teks kecil.
  - Header sticky.
  - Tooltip alasan nonaktif.
  - Pencarian.
- **Prioritas / Risiko:** P2 / UI. **Tidak ada perubahan RBAC.** **Kriteria terima:** semua baris berlabel bahasa Indonesia.

### 3.19 Audit log
- **Sekarang:** 100 entri terbaru, kode aksi mentah, before/after sebagai teks kunci–nilai.
- **Masalah:** tidak ada filter, paginasi, atau label manusiawi.
- **Rekomendasi:**
  - Label aksi.
  - Filter aktor, aksi, dan tanggal.
  - Paginasi server.
  - Detail perubahan di sheet.
- **Prioritas / Risiko:** P2 / Server (query berfilter, hanya-baca). **Kriteria terima:** audit trail tidak berubah; tidak ada data sensitif baru yang tampil.

### 3.20 Navigasi dan layout
- **Admin (`layouts/admin-layout.tsx`):**
  - 8 menu datar tanpa grup.
  - Di HP berupa strip geser dengan label terpotong.
  - Profil dan Keluar disembunyikan di HP.
  - Tidak ada tautan POS/KDS/Kas.
- **POS (`layouts/pos-layout.tsx`):**
  - Tidak ada tautan ke admin, status kas, atau status koneksi.
- **Rekomendasi:**
  - Shell admin dengan sidebar bergrup ([UI_UX_SITEMAP.md](UI_UX_SITEMAP.md)) yang di HP menjadi drawer.
  - Breadcrumb.
  - Pemilih area (Admin ↔ Operasional) bagi yang berhak.
  - Top bar operasional ringkas.
- **Prioritas / Risiko:** **P0** / UI (navigasi memakai `auth.navigation` yang sudah dihitung server).
- **Kriteria terima:** Keluar tersedia di semua layar; Manager berpindah Admin ↔ POS dalam 1 ketukan.

### 3.21 Pengalaman Owner (lintas halaman)
- **Masalah:**
  - Owner punya `order.view`, `cash.view`, `approval.approve`, dan `report.sales`.
  - Tetapi halaman pesanan, kas, dan approval ada di bawah `/pos`, yang mensyaratkan `order.create`.
  - Hasilnya, Owner tidak bisa melihat pesanan dan kas, dan tidak bisa menyetujui pembatalan.
- **Rekomendasi:**
  - Halaman hanya-baca `/admin/orders` (sudah dipetakan ke `order.view` di `RouteAccess`).
  - Kotak masuk approval.
  - Ringkasan sesi kas di admin.
  - Memakai izin yang sudah ada, **tanpa mengubah RBAC**.
- **Prioritas / Risiko:** **P0** bila Owner memang akan memberi approval saat soft launch, P1 bila hanya Manager / Server (route dan query baru, cek izin per outlet).
- **Kriteria terima:**
  - Owner dapat melihat pesanan dan menyetujui pembatalan.
  - Tidak bisa membuat order atau menerima uang.
  - Test live akses baru lolos.

## 4. Inkonsistensi lintas halaman

| Pola | Variasi sekarang |
|---|---|
| Buat data | Halaman terpisah (produk), kartu inline (kategori, pengguna, modifier), input massal (meja) |
| Edit data | Halaman terpisah (produk, outlet), baris inline (kategori), kartu expand (modifier), klik langsung (role) |
| Nonaktifkan | Dengan konfirmasi (produk, outlet), tanpa konfirmasi (pengguna, meja), switch langsung (kategori) |
| Label status order | KDS: New/Preparing/Ready/Completed · `lib/orders.ts`: Antre/Dibuat/Siap/Selesai · customer: Diterima/Dibuat/Siap/Selesai |
| Warna "Aktif" | `secondary` (produk), `default` (pengguna) |
| Mutasi | `usePending`, `useMutation`, `usePost`, inline: empat hook untuk tugas yang sama |
| Uang cepat | POS "Pas/50rb/100rb" vs detail pesanan "Uang pas/Rp 50.000" |
| Tinggi tombol | Bawaan 32 px; halaman menimpa 36–56 px secara acak |
| Error validasi | Banner + toast (produk, login), inline (POS), toast saja (unggah foto) |

## 5. Aksesibilitas (ringkas)

- Target sentuh di bawah 44 px di banyak aksi (lihat §3).
- `text-muted-foreground` di atas latar muted (nav admin, meja nonaktif, placeholder foto) berisiko di bawah 4,5:1.
- Oranye brand dipakai untuk teks kecil di latar terang (kontras ±2,6:1); perlu token `--brand-strong`.
- Input tanpa label terlihat atau nama aksesibel (modifier, kategori di HP).
- Status "Stok menipis" hanya lewat warna teks.
- `PageSkeleton` ada tetapi tidak dipakai.
- Sudah baik: `aria-current` di navigasi, focus ring bawaan komponen, reduced motion global, dialog Base UI menahan fokus.

## 6. Performa (yang sudah diukur dan yang belum)

- **Sudah terukur (dev):**
  - Query per operasi: bayar tunai 12, ubah status KDS 6, papan KDS 4, detail order 8.
  - Sinyal realtime 227–240 ms.
- **Belum diukur:** LCP/CLS/INP landing dan menu. Bundle terbesar `jsx-runtime` 351 KB (109 KB gzip). Diukur di UX-6 dari staging, bukan laptop.
- **Risiko yang sudah terlihat:**
  - Foto menu diunggah apa adanya, maksimal 2 MB, tanpa kompresi atau varian ukuran.
  - Server PHP dev tidak punya GD/Imagick. Optimasi gambar harus dilakukan di browser sebelum unggah, atau lewat fitur transformasi Supabase (paket berbayar).

## 7. Batasan yang dipegang

Tidak ada usulan di dokumen ini yang mengubah harga server, idempotensi, ledger kas, nomor antrean, state machine KDS, aturan refund/approval, inventory guard, audit trail, isolasi guest/tenant, CSRF, atau akses tulis Storage publik.
