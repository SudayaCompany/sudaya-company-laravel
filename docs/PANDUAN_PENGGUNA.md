# Panduan Pemakaian Sudaya Coffee

Untuk Owner, Super Admin, Manager, Kasir, Barista, dan seluruh staf. Alamat web dan akun diberikan oleh pengelola saat serah terima.

## 1. Masuk dan peran

| Peran | Yang bisa dilakukan |
|---|---|
| **Owner** | Dasbor, laporan penjualan & ekspor, pesanan hari ini, persetujuan (pembatalan, koreksi absen, tugas luar, stock opname, purchase order, pengeluaran), kas (hanya-lihat), stok, supplier & PO, resep (pantau), pengeluaran, menu (tambah/ubah, habis, ekspor), kehadiran & rekap absensi, data karyawan, log aktivitas |
| **Super Admin** | Semua fitur, termasuk outlet & meja, akun staf, peran & izin, pengaturan absen |
| **Manager** | Kasir, kas (buka/tutup), barista display, batal & refund tunai, persetujuan, laporan penjualan, stok (item, masuk, keluar, opname), resep, supplier & purchase order (buat, terima barang), pengeluaran, ubah menu & tandai habis, kehadiran, absen |
| **Kasir** | Kasir (POS), pesanan, kas, catat pengeluaran, absen |
| **Barista** | Barista display (antrean), ajukan pembatalan, catat waste / stok keluar, catat pengeluaran, absen |
| **Admin** | Menu, kategori, varian & tambahan, stok (item, masuk, keluar, opname), resep, supplier & purchase order, catat pengeluaran |

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

### Laporan penjualan
Menu **Laporan penjualan** (atau tautan di dasbor). Pilih periode **Hari ini, Kemarin, 7 hari, 30 hari, Bulan ini, Bulan lalu**, atau isi tanggal sendiri (maksimal 92 hari).
- **Omzet bersih, transaksi, rata-rata, item terjual**, masing-masing dibanding periode sebelumnya dengan panjang yang sama (mis. 7 hari lalu). Pesanan lunas yang dibatalkan/direfund ditampilkan terpisah dan tidak dihitung.
- **Omzet harian** (untuk periode lebih dari 1 hari) dan **Jam ramai**: arahkan kursor/ketuk kolom untuk angkanya, atau buka **Tampilkan sebagai tabel**.
- **Menu terlaris**, **Per kategori**, dan **Metode pembayaran**.
- **Ekspor ke spreadsheet**: satu file berisi sheet Ringkasan, Harian, Menu terlaris, Kategori, Per jam, dan Metode bayar. **Cetak** untuk versi kertas/PDF.

### Pelanggan
Menu **Pelanggan**: daftar pelanggan dari pesanan lunas yang mencantumkan nomor HP (checkout QR/kiosk atau delivery): jumlah transaksi, total belanja, rata-rata, transaksi terakhir, menu favorit, **poin** (Rp10.000 = 1 poin) dan **tier** (Member, Silver ≥ Rp300.000, Gold ≥ Rp1.000.000). Nomor HP disamarkan; ketuk ikon mata untuk melihat nomor lengkap. Pesanan yang dibatalkan/direfund tidak dihitung. Penukaran poin belum tersedia.

### Persetujuan
Pembatalan pesanan yang **sudah dibayar**, koreksi absen staf, **tugas luar**, dan **stock opname** yang ada selisihnya harus disetujui Owner atau Manager (bukan orang yang mengajukan). Foto bukti tugas luar bisa dibuka dari kartunya. Menu **Persetujuan**, lalu pilih **Setujui** (dengan konfirmasi) atau **Tolak**. Uang pesanan yang dibatalkan dikembalikan Manager lewat **Catat refund tunai** di detail pesanan saat uang diserahkan.

### Pesanan dan Kas
Menu **Pesanan hari ini** dan **Kas** hanya untuk memantau. Bayar, batal, refund, dan buka/tutup laci dilakukan dari perangkat kasir.

### Ekspor
- **Menu → Ekspor ke spreadsheet**: daftar menu, harga, varian, dan ketersediaan per outlet (file `.xlsx`, terbuka di Excel atau Google Sheets).
- **Laporan penjualan → Ekspor ke spreadsheet**: ringkasan, harian, menu terlaris, kategori, per jam, dan metode bayar dalam satu file.
- **Rekap absensi → Ekspor ke spreadsheet**: hari hadir, jam kerja, koreksi, dan sesi tugas luar per karyawan, ditambah sheet **Detail absen** (jam masuk dan pulang tiap sesi) untuk payroll. Keterlambatan belum dihitung karena jadwal shift belum diatur di sistem.

## 3. Super Admin: persiapan sebelum outlet buka

1. **Outlet & meja**: periksa nama dan alamat outlet, lalu tambahkan nomor meja. Pilih **Cetak QR meja** untuk lembar QR siap tempel (satu kartu per meja aktif). Tes satu QR dengan kamera HP sebelum mencetak semua, dan buka lembar QR dari alamat web resmi (bukan alamat uji), karena alamat itu yang tercetak di QR.
2. **Menu**: buat **Kategori**, lalu **Varian & tambahan** (mis. ukuran, gula), lalu **Menu** (foto, harga, varian). Foto JPG, PNG, atau WebP, maksimal 2 MB.
3. **Staf**: undang setiap staf dengan email dan peran. Staf membuat password sendiri lewat tautan undangan.
4. **Pengaturan absen**: berdiri di dalam outlet dengan HP atau laptop, pilih **Pakai lokasi perangkat ini**, lalu **Simpan lokasi**. Radius yang disarankan 30 m.
5. **Data karyawan**: tambah karyawan (kode, nama, jabatan, outlet) dan **tautkan ke akun staf** agar bisa absen dari HP.

## 4. Stok (Manager, Admin, Barista)

Grup menu **Stok**. Satuan bisa dicatat dalam satuan lain yang setara (mis. stok dalam ml boleh diisi 2 l; dalam g boleh diisi 1 kg).
1. **Stok → Tambah item**: bahan baku (kopi, susu), produk jadi (roti, minuman botol), atau kemasan (cup, sedotan). Isi SKU, satuan, stok minimum, dan saldo awal. **Produk jadi** bisa ditautkan ke menu: stoknya **otomatis berkurang saat pesanan dibayar** dan kembali bila pembatalan disetujui. Pembayaran ditolak bila stok produk jadi tidak cukup.
2. **Stok masuk**: pembelian, retur, atau penyesuaian tambah. Harga beli terakhir tersimpan otomatis.
3. **Stok keluar & waste**: tumpah, rusak, kedaluwarsa, salah produksi, dipakai sendiri. Alasan wajib; foto bukti bisa diambil langsung dari kamera HP.
4. **Stock opname** (mis. tiap akhir bulan): **Mulai stock opname**, isi hitungan fisik tiap item, lalu **Ajukan opname**. Tanpa selisih langsung diterapkan; ada selisih → alasan wajib dan menunggu persetujuan Owner/Manager di **Persetujuan**.
5. **Riwayat stok**: semua gerakan stok per item, siapa yang mencatat, dan foto buktinya. Riwayat tidak bisa diubah atau dihapus.
6. **Resep (BOM)**: pilih menu, isi bahan per 1 porsi (mis. Latte: biji 18 g, susu 150 ml, cup 1 pcs). Saat pesanan dibayar bahan dipotong otomatis dan kembali bila pembatalan disetujui. Penjualan **tidak pernah ditolak** karena bahan kurang: stok dipotong sampai habis dan kekurangannya tercatat di Log aktivitas — tanda perlu stok masuk atau opname.
7. **Supplier → Purchase order**: daftarkan supplier (kontak, produk, termin). **Buat PO** (barang, jumlah, harga per satuan) → diajukan ke **Persetujuan** → setelah disetujui, saat barang datang buka PO lalu **Terima barang** (isi jumlah yang benar-benar datang). Stok bertambah otomatis sebagai Pembelian dan harga beli item diperbarui.

Status di daftar stok: **Aman**, **Menipis** (di bawah minimum), **Habis**. Item menipis/habis juga tampil di dasbor Owner.

## 5. Pengeluaran

Menu **Pengeluaran** (atau pintasan **Catat pengeluaran** di beranda staf): tanggal, nominal, kategori (parkir, transport, pembelian kecil, perawatan, kebersihan, lainnya), keterangan, dan foto nota. Pengeluaran menunggu persetujuan Owner/Manager di **Persetujuan** (bukan orang yang mencatat). Halaman ini menampilkan total disetujui per bulan dan per kategori; **Laporan penjualan** menampilkan "omzet dikurangi pengeluaran" untuk periode yang sama. Uang yang diambil dari laci kasir tetap dicatat juga di **Kas → Kas keluar**.

## 6. Kasir

1. **Buka kas** setiap awal shift: menu **Kas**, isi modal awal (uang di laci), lalu **Buka sesi kas**. Pembayaran tunai baru bisa diterima setelah kas dibuka.
2. **Kasir (POS)**: ketuk menu untuk menambah (menu dengan varian membuka pilihan), pilih **Bawa pulang / Makan di sini / Antar**, isi uang diterima (atau ketuk nominal cepat), lalu **Bayar tunai & proses**. Nomor antrean dan kembalian tampil besar, dan struk bisa langsung dicetak.
3. Pesanan dari **QR meja** atau **kiosk** masuk ke **Pesanan → Menunggu bayar**. Buka pesanan, terima uang, lalu **Konfirmasi pembayaran**.
4. **Batal & refund**: akun Kasir tidak bisa membatalkan pesanan. Minta Manager (atau Barista mengajukan pembatalan). Pesanan belum dibayar langsung batal; yang sudah dibayar butuh persetujuan Owner/Manager, lalu Manager mencatat **refund tunai** saat uang diserahkan.
5. **Tutup kas** di akhir shift: hitung uang di laci, isi **Uang fisik**, lalu tulis alasan bila ada selisih.

## 7. Barista

Menu **Barista display** menampilkan antrean **Antre → Dibuat → Siap**. Ketuk **Mulai buat**, lalu **Tandai siap**, lalu **Sudah diambil** sesuai proses. Pesanan baru muncul otomatis. Bila status koneksi bertuliskan "Cek berkala" atau "Tidak ada internet", periksa Wi-Fi.

## 8. Absen staf (GPS + selfie)

1. Buka **Absen** dari beranda staf (atau menu **Absen** di kasir).
2. Ketuk **Absen masuk**. Kamera depan terbuka; ambil selfie.
3. Lokasi dicek otomatis. Bila muncul "Lokasi sesuai", ketuk **Kirim absen masuk**.
4. Saat pulang, lakukan hal yang sama dengan **Absen pulang**. Jam kerja dihitung otomatis.

Bila absen gagal:
- **"Anda di luar area absen"**: pastikan berada di dalam outlet, lalu **Cek lokasi lagi**.
- **"Akurasi GPS … melebihi batas"**: nyalakan lokasi presisi dan pindah ke dekat pintu atau jendela.
- **"Izin lokasi ditolak"**: izinkan lokasi untuk situs ini di pengaturan browser.
- Lupa absen, atau HP mati: **Riwayat & koreksi absen → Ajukan koreksi absen**. Koreksi berlaku setelah disetujui Owner/Manager.

**Tugas luar** (mis. belanja bahan, event, kantor pusat): **Absen → Tugas luar outlet** (atau tile **Tugas luar** di beranda staf). Isi tanggal, jam mulai–selesai, lokasi, tujuan, keterangan, dan foto bukti bila ada, lalu **Ajukan tugas luar**. Setelah disetujui Owner/Manager, absen pada jam tugas boleh dilakukan dari lokasi tugas (di luar radius outlet); selfie dan GPS tetap wajib dan tercatat. Pengajuan yang belum diputuskan bisa dibatalkan sendiri. Tugas luar yang disetujui tampil di halaman **Kehadiran**.

Foto dan lokasi hanya dipakai sebagai bukti kehadiran dan hanya bisa dibuka Owner/Manager. **Foto selfie dan foto bukti tugas luar dihapus otomatis setelah 90 hari**; jam, lokasi absen, dan data pengajuan tetap tersimpan.

## 9. Pelanggan

- **QR meja**: pelanggan memindai QR di meja, memilih menu, lalu membayar di kasir. Status pesanan bisa dipantau dari tautan yang tampil.
- **Kiosk**: layar pemesanan mandiri di outlet; pembayaran tetap di kasir.
- Pembayaran QRIS belum aktif. Semua pembayaran tunai di kasir.

## 10. Bantuan cepat

| Masalah | Yang dilakukan |
|---|---|
| Tombol bayar tunai tidak muncul | Kas belum dibuka. Buka di menu **Kas** |
| Menu tidak tampil di halaman pelanggan | Periksa menu **Aktif** dan tidak ditandai **Habis** untuk outlet itu |
| Staf tidak bisa absen ("belum terhubung") | Tautkan akun staf di **Data karyawan** |
| Semua staf gagal absen di dalam outlet | Periksa titik lokasi dan radius di **Pengaturan absen** |
| Ada selisih kas | Lihat **Kas → Riwayat sesi** dan **Log aktivitas** |
| Pembayaran ditolak "Stok … tidak cukup" | Produk jadi yang ditautkan ke menu habis. Catat **Stok masuk**, atau tandai menu **Habis** |
| Angka stok tidak sesuai fisik | Lakukan **Stock opname**; selisih berlaku setelah disetujui |
| Stok bahan habis padahal masih ada | Periksa resep menu (jumlah per porsi) lalu lakukan **Stock opname** |
| PO tidak bisa diterima | PO harus disetujui dulu di **Persetujuan** |
