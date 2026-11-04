# Persiapan data master Sudaya Coffee

Disusun 4 November 2026. Checklist data **asli** yang harus disiapkan owner/manager sebelum UAT staging dan soft launch. Isi dokumen ini tidak memuat harga, nama karyawan, atau data contoh. Kolom "Cara masuk ke sistem" menunjukkan apakah data bisa diisi lewat aplikasi Laravel saat ini.

## Company dan outlet

| Data | Keterangan | Cara masuk ke sistem | Siap? |
|---|---|---|---|
| Nama company dan brand resmi | Tampil di admin | Seed awal memakai "Sudaya Company"/"Sudaya Coffee"; perubahan lewat SQL yang disetujui | ☐ |
| Kode outlet | Dipakai di URL QR, mis. `/order?outlet=SDY01&table=T03`; huruf besar dan angka | Seed awal `SDY01`; mengubahnya setelah QR dicetak memutus QR lama | ☐ |
| Nama outlet | Tampil di menu customer, struk, KDS | Seed awal "Sudaya Coffee — Outlet 01" | ☐ |
| Alamat dan nomor telepon outlet | Untuk struk/kontak (struk saat ini memuat nama outlet) | Kolom tersedia di database | ☐ |
| Koordinat (lat/long) dan radius absensi | Dipakai modul absensi (M7, ditunda) | Kolom tersedia | ☐ |
| Zona waktu | Default `Asia/Jakarta`; menentukan tanggal operasional dan reset nomor antrean harian | Kolom tersedia | ☐ |
| Prefix nomor antrean | Default `SDY` → `SDY-001` | Kolom tersedia | ☐ |
| Batas waktu bayar order QR/kiosk | Default 15 menit | Kolom tersedia | ☐ |
| Jam operasional | Informasi | Kolom tersedia | ☐ |

> **Celah saat ini:** Laravel belum punya halaman admin outlet. Data outlet di atas, selain yang dibuat seed, harus diisi lewat SQL yang dibaca dan disetujui owner, atau lewat halaman admin outlet yang dibangun lebih dulu. Keputusan ini perlu diambil sebelum UAT.

## Menu

| Data | Keterangan | Cara masuk ke sistem | Siap? |
|---|---|---|---|
| Kategori | Nama dan urutan tampil | `/admin/categories` | ☐ |
| Produk | Nama, kategori, deskripsi singkat | `/admin/products` | ☐ |
| SKU | Opsional, unik per brand; dipakai saat stok (M5) | `/admin/products` | ☐ |
| Harga | Rupiah bulat, 0–10.000.000 | `/admin/products` | ☐ |
| Modifier | Grup (mis. Size, Sugar Level, Topping): wajib/opsional, pilihan tunggal/ganda, min/maks, opsi default, selisih harga | `/admin/modifiers`, lalu hubungkan ke produk | ☐ |
| Foto produk | JPG/PNG/WebP maks 2 MB, sebaiknya persegi | `/admin/products` | ☐ |
| Ketersediaan per outlet | Produk yang tidak dijual di outlet tertentu | Tanda sold out per outlet di `/admin/products` | ☐ |
| Tautan stok | **Jangan** tautkan produk ke item stok sebelum M5. Produk yang tertaut tidak bisa dibayar (inventory guard) | — | ☐ |

Cara kerja yang disarankan: siapkan menu dulu di spreadsheet (kategori, produk, harga, modifier), diperiksa owner, lalu diinput satu kali di staging untuk UAT dan diulang di production.

## Staf dan akses

| Data | Keterangan | Cara masuk ke sistem | Siap? |
|---|---|---|---|
| Daftar staf | Nama lengkap dan email kerja yang aktif (untuk link undangan) | `/admin/users` → undang | ☐ |
| Peran per staf | Super Admin, Owner, Manager, Kasir, Barista, dll; outlet tempat bertugas | `/admin/users` | ☐ |
| Minimal 2 Manager per outlet | Approval pembatalan tidak bisa oleh pemohonnya sendiri | — | ☐ |
| Keputusan Kasir dan Barista satu orang atau terpisah (EXT-009) | Menentukan role yang diberikan | — | ☐ |
| Penanggung jawab akun Super Admin | Minimal 2 orang, akses darurat | `sudaya:admin-bootstrap` untuk yang pertama | ☐ |

## Meja dan QR

| Data | Keterangan | Siap? |
|---|---|---|
| Daftar nomor meja | Huruf/angka/tanda hubung, maks 10 karakter (mis. T01) | ☐ |
| QR meja | Berisi `https://<domain>/order?outlet=<KODE>&table=<MEJA>`; dibuat dan dicetak di luar sistem (EXT-026) | ☐ |
| QR kasir (tanpa meja) | `https://<domain>/order?outlet=<KODE>&src=qr` | ☐ |
| URL kiosk | `https://<domain>/kiosk?outlet=<KODE>` | ☐ |

## Perangkat dan jaringan

| Perangkat | Kebutuhan | Siap? |
|---|---|---|
| Perangkat POS (tablet/laptop) | Browser Chrome terbaru; layar tablet atau lebih besar disarankan; tetap bisa di HP | ☐ |
| Printer struk | Thermal 80 mm yang bisa dipakai dari dialog print browser perangkat POS; uji cetak di UAT | ☐ |
| Perangkat KDS | Tablet landscape atau layar desktop dekat bar; Chrome; tidak tidur otomatis | ☐ |
| Perangkat kiosk (bila dipakai) | Tablet landscape, mode layar penuh, tanpa akun staf yang login | ☐ |
| Wi-Fi outlet | Stabil untuk POS, KDS, dan customer; WebSocket ke Supabase tidak diblokir | ☐ |
| Cadangan internet | Hotspot seluler untuk POS/KDS bila Wi-Fi mati | ☐ |
| Laci uang dan modal awal | Jumlah modal awal harian ditetapkan owner | ☐ |

## Keputusan operasional sementara (owner, 4 November 2026)

| ID | Keputusan sementara | Kondisi sistem saat ini | Yang masih perlu dikerjakan |
|---|---|---|---|
| EXT-009 | **Kasir dan Barista adalah peran terpisah.** | Sesuai: role `cashier` dan `barista` sudah terpisah di RBAC. Kasir: terima tunai, kas, POS. Barista: KDS, status produksi, ajukan pembatalan | Tetapkan peran tiap staf saat undangan |
| EXT-012 | **Kiosk menampilkan referensi order, dan nomor antrean bila sudah tersedia.** | Kiosk menampilkan **kode referensi** dan "Menunggu pembayaran". Nomor antrean baru terbit saat kasir menerima uang, dan tercetak di struk dari kasir. Kiosk sengaja tidak menyimpan token pesanan, jadi layar kiosk tidak memantau status setelah customer pergi | Prosedur: kasir menyerahkan struk bernomor antrean saat menerima uang. Menampilkan nomor di kiosk/layar antrean publik adalah kandidat fitur setelah UAT |
| EXT-026 | **QR meja membawa identitas outlet dan meja yang divalidasi.** | Kode outlet divalidasi (outlet tidak dikenal/nonaktif ditolak). Nomor meja hanya divalidasi formatnya (huruf, angka, `-`, maks 10); belum dicocokkan ke daftar meja resmi karena master meja belum ada | Kandidat pekerjaan sebelum UAT: master meja per outlet + penolakan nomor meja di luar daftar (lihat GO_LIVE_READINESS) |

## Kebijakan pembatalan yang bisa dijalankan tim

Diverifikasi dari RBAC dan alur yang sudah lolos test (live, E2E, browser):

| Langkah | Siapa yang bisa | Catatan |
|---|---|---|
| Batalkan order **belum dibayar** | Barista, Manager, Super Admin | Langsung batal dengan alasan. Kasir tidak punya izin membatalkan |
| Ajukan pembatalan order **lunas** | Barista, Manager, Super Admin | Butuh alasan; satu pengajuan aktif per order |
| Setujui/tolak pengajuan | Manager, Super Admin (lewat POS → detail pesanan) | **Bukan pemohonnya sendiri** (dicek per akun). Owner punya izin approval tetapi belum bisa membuka POS di Laravel (halaman pesanan admin belum dipindahkan) |
| Catat refund tunai | Manager, Super Admin | Dari sesi kas terbuka; saldo laci harus cukup; hanya sekali |

Agar selalu bisa dijalankan tanpa self-approval:
- Setiap shift ada minimal **satu Manager** yang tidak menjadi pemohon. Pengajuan sebaiknya dibuat Barista.
- Bila Manager terpaksa mengajukan sendiri, persetujuan dari **Manager lain atau Super Admin**. Bisa dari perangkat lain/jarak jauh, asalkan login dengan akunnya sendiri.
- Jangan berbagi akun. Berbagi akun meniadakan kontrol "bukan pemohon sendiri" dan merusak jejak audit.

## Keputusan terkait yang belum diambil

| ID | Keputusan | Dampak ke data |
|---|---|---|
| EXT-021 | Domain | Isi QR meja dan link undangan |
| — | Cara produksi/pencetakan QR meja (isi QR sudah ditetapkan di atas) | Pencetakan QR |
| — | Halaman pengaturan outlet (kandidat pekerjaan sebelum UAT, setelah M4 disetujui) vs SQL yang disetujui | Data outlet |
