# Panduan Pemakaian Sudaya Coffee

Untuk Owner, Super Admin, Manager, Kasir, Barista, dan seluruh staf. Alamat web dan akun diberikan oleh pengelola saat serah terima.

## 1. Masuk dan peran

| Peran | Yang bisa dilakukan |
|---|---|
| **Owner** | Dasbor penjualan, pesanan hari ini, persetujuan pembatalan & koreksi absen, kas (hanya-lihat), menu (tambah/ubah, habis, ekspor), kehadiran & rekap absensi, data karyawan, log aktivitas |
| **Super Admin** | Semua fitur, termasuk outlet & meja, akun staf, peran & izin, pengaturan absen |
| **Manager** | Kasir, kas (buka/tutup), barista display, batal & refund tunai, persetujuan, ubah menu & tandai habis, kehadiran, absen |
| **Kasir** | Kasir (POS), pesanan, kas, absen |
| **Barista** | Barista display (antrean), ajukan pembatalan, absen |
| **Admin** | Menu, kategori, varian & tambahan |

- Masuk lewat halaman **Login** dengan email dan password masing-masing. Setelah masuk, setiap orang langsung diarahkan ke halaman sesuai perannya.
- Lupa password: minta Super Admin membuat **tautan pemulihan** di menu **Staf**.
- Jangan memakai satu akun bersama. Semua perubahan penting tercatat atas nama akun di **Log aktivitas**.

## 2. Owner

### Dasbor
Angka hari ini (tanggal operasional outlet), diperbarui otomatis tiap menit atau lewat tombol **Perbarui**:
- **Omzet bersih**: total pesanan lunas, tidak termasuk yang dibatalkan atau direfund. Ada juga jumlah transaksi, rata-rata per transaksi, dan item terjual.
- **Menu terlaris hari ini**.
- **Perlu tindakan**: pembatalan dan koreksi absen yang menunggu persetujuan, pesanan belum dibayar, menu yang ditandai habis, dan kas yang belum dibuka. Ketuk baris untuk langsung ke halamannya.
- **Pesanan**, **Kas** (saldo sistem dan selisih tutup kas terakhir), dan **Kehadiran hari ini**.

### Persetujuan
Pembatalan pesanan yang **sudah dibayar** dan koreksi absen staf harus disetujui Owner atau Manager (bukan orang yang mengajukan). Menu **Persetujuan**, lalu pilih **Setujui** (dengan konfirmasi) atau **Tolak**. Uang pesanan yang dibatalkan dikembalikan Manager lewat **Catat refund tunai** di detail pesanan saat uang diserahkan.

### Pesanan dan Kas
Menu **Pesanan hari ini** dan **Kas** hanya untuk memantau. Bayar, batal, refund, dan buka/tutup laci dilakukan dari perangkat kasir.

### Ekspor
- **Menu → Ekspor ke spreadsheet**: daftar menu, harga, varian, dan ketersediaan per outlet (file `.xlsx`, terbuka di Excel atau Google Sheets).
- **Rekap absensi → Ekspor ke spreadsheet**: hari hadir, jam kerja, dan koreksi per karyawan untuk rentang tanggal yang dipilih.

## 3. Super Admin: persiapan sebelum outlet buka

1. **Outlet & meja**: periksa nama dan alamat outlet, lalu tambahkan nomor meja. Pilih **Cetak QR meja** untuk lembar QR siap tempel (satu kartu per meja aktif). Tes satu QR dengan kamera HP sebelum mencetak semua, dan buka lembar QR dari alamat web resmi (bukan alamat uji), karena alamat itu yang tercetak di QR.
2. **Menu**: buat **Kategori**, lalu **Varian & tambahan** (mis. ukuran, gula), lalu **Menu** (foto, harga, varian). Foto JPG, PNG, atau WebP, maksimal 2 MB.
3. **Staf**: undang setiap staf dengan email dan peran. Staf membuat password sendiri lewat tautan undangan.
4. **Pengaturan absen**: berdiri di dalam outlet dengan HP atau laptop, pilih **Pakai lokasi perangkat ini**, lalu **Simpan lokasi**. Radius yang disarankan 30 m.
5. **Data karyawan**: tambah karyawan (kode, nama, jabatan, outlet) dan **tautkan ke akun staf** agar bisa absen dari HP.

## 4. Kasir

1. **Buka kas** setiap awal shift: menu **Kas**, isi modal awal (uang di laci), lalu **Buka sesi kas**. Pembayaran tunai baru bisa diterima setelah kas dibuka.
2. **Kasir (POS)**: ketuk menu untuk menambah (menu dengan varian membuka pilihan), pilih **Bawa pulang / Makan di sini / Antar**, isi uang diterima (atau ketuk nominal cepat), lalu **Bayar tunai & proses**. Nomor antrean dan kembalian tampil besar, dan struk bisa langsung dicetak.
3. Pesanan dari **QR meja** atau **kiosk** masuk ke **Pesanan → Menunggu bayar**. Buka pesanan, terima uang, lalu **Konfirmasi pembayaran**.
4. **Batal & refund**: akun Kasir tidak bisa membatalkan pesanan. Minta Manager (atau Barista mengajukan pembatalan). Pesanan belum dibayar langsung batal; yang sudah dibayar butuh persetujuan Owner/Manager, lalu Manager mencatat **refund tunai** saat uang diserahkan.
5. **Tutup kas** di akhir shift: hitung uang di laci, isi **Uang fisik**, lalu tulis alasan bila ada selisih.

## 5. Barista

Menu **Barista display** menampilkan antrean **Antre → Dibuat → Siap**. Ketuk **Mulai buat**, lalu **Tandai siap**, lalu **Sudah diambil** sesuai proses. Pesanan baru muncul otomatis. Bila status koneksi bertuliskan "Cek berkala" atau "Tidak ada internet", periksa Wi-Fi.

## 6. Absen staf (GPS + selfie)

1. Buka **Absen** dari beranda staf (atau menu **Absen** di kasir).
2. Ketuk **Absen masuk**. Kamera depan terbuka; ambil selfie.
3. Lokasi dicek otomatis. Bila muncul "Lokasi sesuai", ketuk **Kirim absen masuk**.
4. Saat pulang, lakukan hal yang sama dengan **Absen pulang**. Jam kerja dihitung otomatis.

Bila absen gagal:
- **"Anda di luar area absen"**: pastikan berada di dalam outlet, lalu **Cek lokasi lagi**.
- **"Akurasi GPS … melebihi batas"**: nyalakan lokasi presisi dan pindah ke dekat pintu atau jendela.
- **"Izin lokasi ditolak"**: izinkan lokasi untuk situs ini di pengaturan browser.
- Lupa absen, atau HP mati: **Riwayat & koreksi absen → Ajukan koreksi absen**. Koreksi berlaku setelah disetujui Owner/Manager.

Foto dan lokasi hanya dipakai sebagai bukti kehadiran dan hanya bisa dibuka Owner/Manager. **Foto dihapus otomatis setelah 90 hari**; jam dan lokasi absen tetap tersimpan.

## 7. Pelanggan

- **QR meja**: pelanggan memindai QR di meja, memilih menu, lalu membayar di kasir. Status pesanan bisa dipantau dari tautan yang tampil.
- **Kiosk**: layar pemesanan mandiri di outlet; pembayaran tetap di kasir.
- Pembayaran QRIS belum aktif. Semua pembayaran tunai di kasir.

## 8. Bantuan cepat

| Masalah | Yang dilakukan |
|---|---|
| Tombol bayar tunai tidak muncul | Kas belum dibuka. Buka di menu **Kas** |
| Menu tidak tampil di halaman pelanggan | Periksa menu **Aktif** dan tidak ditandai **Habis** untuk outlet itu |
| Staf tidak bisa absen ("belum terhubung") | Tautkan akun staf di **Data karyawan** |
| Semua staf gagal absen di dalam outlet | Periksa titik lokasi dan radius di **Pengaturan absen** |
| Ada selisih kas | Lihat **Kas → Riwayat sesi** dan **Log aktivitas** |
