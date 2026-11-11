# Naskah demo presentasi Sudaya Coffee

Panduan singkat untuk mempresentasikan web ke klien. Durasi ±15 menit. Siapkan 3 perangkat: **HP** (pelanggan), **laptop** (kasir/admin), **tablet atau laptop kedua** (barista).

## Persiapan (H-1)

1. **Alamat web**: QR berisi alamat web yang sedang dibuka saat dicetak. Cetak QR dari server presentasi (VPS/domain), bukan dari laptop development.
2. **Menu**: 22 menu asli Sudaya sudah masuk sebagai **nonaktif** (harga Rp0). Isi harga di **Admin → Menu** lalu aktifkan. Sampai menu asli aktif, menu contoh (`php artisan sudaya:demo-menu`, SKU `CONTOH-`) tetap bisa dipakai untuk demo; hapus dengan `php artisan sudaya:demo-menu --remove` sebelum go-live. Foto menu memakai placeholder "Foto menyusul" sampai foto asli diunggah.
3. **Meja kantor**: buka **Admin → Outlet & meja → (outlet) → Daftar meja**, tambahkan nomor meja (mis. `L3-01`), lalu **Cetak QR meja** dan tempel di meja demo.
4. **QRIS**: di halaman outlet yang sama, kartu **Pembayaran QRIS** → pilih gambar QRIS resmi Sudaya → **Simpan QRIS**. Antar ke meja gratis (tanpa biaya antar).
5. **Konten situs**: **Admin → Konten situs** → isi kontak (WhatsApp, Instagram, email), info pelatihan, dan tautan sedekah program petani kopi di Sahabat Berbagi.
6. **Akun & kas**: siapkan akun Kasir dan Barista (**Admin → Staf → Undang staf**). Buka kas di perangkat kasir (**Kas → Buka sesi kas**) — uang tunai yang diterima barista saat mengantar masuk sesi kas ini.

## Alur demo

| Langkah | Perangkat | Yang ditunjukkan |
|---|---|---|
| 1 | HP | Halaman depan (tanpa login): tentang Sudaya, program **pelatihan kopi** & **pemberdayaan petani kopi**, info pelatihan, **sedekah lewat Sahabat Berbagi**, kontak di footer |
| 2 | HP | Scan QR meja → menu tampil dengan kategori & pencarian |
| 3 | HP | Pilih menu → varian (Ice/Hot, ukuran, gula, es) → **catatan cepat** "Less sugar", "Less ice" → Tambah |
| 4 | HP | Keranjang → Checkout → **Antar ke meja** (gratis) atau **Ambil di counter**; isi **nama, lantai, bagian**; nomor meja otomatis dari QR; nomor WA (opsional); **Pesan menu di luar daftar** (opsional) |
| 5 | HP | Pilih **Tunai** → nomor antrean langsung muncul + "Bayar tunai Rp… ke barista saat pesanan diantar" (atau pilih **QRIS** → QRIS Sudaya + nominal persis) |
| 6 | HP | Ketuk **Aktifkan notifikasi saat pesanan siap** |
| 7 | Tablet barista | Kartu **ANTAR KE MEJA · BELUM BAYAR** + meja, lantai, bagian, catatan → **Mulai buat** → **Tandai siap** → **Kabari via WhatsApp** |
| 8 | HP | Banner **"Pesanan Anda sudah siap!"** + "Siapkan uang tunai", bunyi & getar, notifikasi browser |
| 9 | Tablet barista | Antar ke meja, terima uang → **Terima pembayaran** → isi uang diterima (kembalian otomatis) → **Konfirmasi pembayaran** → **Kembali ke layar barista** → **Sudah diantar** |
| 10 | Laptop kasir | Pesanan QRIS: **Pesanan → Menunggu bayar** → cek dana masuk → **Konfirmasi QRIS diterima** (bisa juga oleh barista) |
| 11 | Laptop owner | **Dasbor**, **Laporan penjualan**, **Stok**, **Pelanggan**, **Persetujuan** |
| 12 | Laptop admin | **Menu**: isi harga menu asli & aktifkan; **Konten situs**: ubah info pelatihan/kontak, langsung tampil di halaman depan |
| 13 | HP staf | Pasang aplikasi Sudaya (Chrome → **Pasang aplikasi**) → absen GPS + selfie dari aplikasi |

## Catatan untuk penjelasan ke klien

- **Pesan langsung di outlet** tetap bisa lewat Kasir (POS), QR counter, atau kiosk.
- **Barista**: absen + semua urusan pesanan (termasuk menerima uang saat mengantar). Stok dan pengeluaran diurus Admin/Manager.
- **QRIS**: memakai QRIS statis milik Sudaya; kasir/barista mengonfirmasi setelah dana terlihat masuk. QRIS otomatis (dana terverifikasi sistem) butuh akun payment gateway (mis. Midtrans/Xendit).
- **WhatsApp otomatis** (terkirim tanpa staf menekan tombol) butuh WhatsApp Business API berbayar; saat ini memakai tombol **Kabari via WhatsApp** dan notifikasi di halaman pesanan.
- **Harga request di luar menu**: request tercatat dan terlihat staf; penyesuaian harganya di sistem menunggu keputusan aturan bayar.
- **Sedekah**: tombol membuka situs Sahabat Berbagi di tab baru; uang tidak melewati sistem Sudaya.
