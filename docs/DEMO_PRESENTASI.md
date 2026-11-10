# Naskah demo presentasi Sudaya Coffee

Panduan singkat untuk mempresentasikan web ke klien. Durasi ±15 menit. Siapkan 3 perangkat: **HP** (pelanggan), **laptop** (kasir/admin), **tablet atau laptop kedua** (barista).

## Persiapan (H-1)

1. **Alamat web**: QR berisi alamat web yang sedang dibuka saat dicetak. Cetak QR dari server presentasi (VPS/domain), bukan dari laptop development.
2. **Menu contoh**: di server presentasi jalankan `php artisan sudaya:demo-menu`. Hasilnya 9 menu contoh dalam 3 kategori, dengan varian ukuran, gula (less sugar), es (less ice), dan tambahan. Semua SKU berawalan `CONTOH-` dan deskripsinya menyebut "contoh". Foto memakai placeholder "Foto menyusul". Sebelum go-live, hapus dengan `php artisan sudaya:demo-menu --remove`.
3. **Meja kantor**: buka **Admin → Outlet & meja → (outlet) → Daftar meja**, lalu tambahkan nomor meja, mis. `L3-01`, `L3-02`. Pilih **Cetak QR meja** dan tempel satu QR di meja demo.
4. **Biaya antar & QRIS**: di halaman outlet yang sama, kartu **Pesanan meja kantor**:
   - isi **Biaya antar ke meja**, mis. Rp 3.000 (0 = gratis);
   - pilih **gambar QRIS** resmi Sudaya (foto stiker QRIS dari bank/penyedia), lalu **Simpan**.
5. **Akun**: siapkan akun Kasir, Barista, dan Owner. Buka kas di perangkat kasir (**Kas → Buka sesi kas**).

## Alur demo

| Langkah | Perangkat | Yang ditunjukkan |
|---|---|---|
| 1 | HP | Scan QR meja → menu tampil dengan kategori & pencarian |
| 2 | HP | Pilih menu → varian (ukuran, gula, es) → **catatan cepat** "Less sugar", "Less ice" → Tambah |
| 3 | HP | Keranjang → Checkout → **Antar ke meja (+biaya antar)** atau **Ambil di counter**; isi **nama, lantai, bagian**; nomor meja otomatis dari QR; isi nomor WA (opsional); **Pesan menu di luar daftar** (opsional) |
| 4 | HP | Pilih **QRIS** → halaman status menampilkan QRIS Sudaya + nominal persis (bisa disimpan untuk "scan dari galeri") |
| 5 | HP | Ketuk **Aktifkan notifikasi saat pesanan siap** |
| 6 | Laptop kasir | **Pesanan → Menunggu bayar** → buka pesanan → cek dana masuk di aplikasi QRIS → **Konfirmasi QRIS diterima**. Biaya antar & request di luar menu terlihat |
| 7 | HP | Halaman status berubah sendiri: nomor antrean muncul |
| 8 | Tablet barista | Kartu bertanda **ANTAR KE MEJA** + meja, lantai, bagian, catatan → **Mulai buat** → **Tandai siap** → **Kabari via WhatsApp** (membuka WhatsApp dengan pesan siap kirim) |
| 9 | HP | Banner **"Pesanan Anda sudah siap!"**, bunyi & getar, notifikasi browser |
| 10 | Laptop owner | **Dasbor**, **Laporan penjualan** (omzet termasuk biaya antar), **Stok**, **Pelanggan**, **Persetujuan** |
| 11 | Laptop admin | **Menu → Tambah menu**: contoh cara memasukkan menu asli (nama, kategori, harga, varian, foto) |

## Catatan untuk penjelasan ke klien

- **Pesan langsung di outlet** tetap bisa lewat Kasir (POS), QR counter, atau kiosk.
- **QRIS**: memakai QRIS statis milik Sudaya; kasir mengonfirmasi setelah dana terlihat masuk. QRIS otomatis (dana terverifikasi sistem) butuh akun payment gateway (mis. Midtrans/Xendit).
- **WhatsApp otomatis** (terkirim tanpa staf menekan tombol) butuh WhatsApp Business API berbayar; saat ini memakai tombol **Kabari via WhatsApp** dan notifikasi di halaman pesanan.
- **Harga request di luar menu**: request tercatat dan terlihat staf. Penyesuaian harganya oleh barista di sistem adalah tahap berikutnya.
