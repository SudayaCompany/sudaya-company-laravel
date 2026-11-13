# Panduan Pemakaian Sudaya Coffee

Untuk Owner, Super Admin, Manager, Kasir, Barista, dan seluruh staf. Alamat web dan akun diberikan oleh pengelola saat serah terima.

## 1. Masuk dan peran

| Peran | Yang bisa dilakukan |
|---|---|
| **Owner** | Dasbor, laporan penjualan & ekspor, pesanan hari ini, persetujuan (pembatalan, koreksi absen, tugas luar, stock opname, purchase order, pengeluaran), kas (hanya-lihat), stok, supplier & PO, resep (pantau), pengeluaran, menu (tambah/ubah, habis, ekspor), kehadiran & rekap absensi, data karyawan, log aktivitas |
| **Super Admin** | Semua fitur, termasuk outlet & meja, akun staf, peran & izin, pengaturan absen |
| **Manager** | Kasir, kas (buka/tutup), barista display, batal & refund tunai, persetujuan, laporan penjualan, stok (item, masuk, keluar, opname), resep, supplier & purchase order (buat, terima barang), pengeluaran, ubah menu & tandai habis, kehadiran, absen |
| **Kasir** (perangkat Sudaya) | Pusat pesanan: **Pesanan masuk** (alarm pesanan baru, terima, antar, selesai), kasir (POS) untuk pesanan langsung, terima tunai/QRIS termasuk **bayar saat diantar**, batalkan pesanan belum bayar, kas (buka/tutup), absen |
| **Barista** | **Absen saja** (GPS + selfie dengan foto wajah terdaftar), riwayat & koreksi absen, tugas luar. Pesanan diproses lewat akun kasir agar tidak dobel (keputusan owner 12 November 2026) |
| **Admin** | Menu, kategori, varian & tambahan, stok (item, masuk, keluar, opname), resep, supplier & purchase order, catat pengeluaran |

- Masuk lewat halaman **Login** dengan email dan password masing-masing. Setelah masuk, setiap orang langsung diarahkan ke halaman sesuai perannya.
- Lupa password: minta Super Admin membuat **tautan pemulihan** di menu **Staf**.
- Jangan memakai satu akun bersama. Semua perubahan penting tercatat atas nama akun di **Log aktivitas**.
- **Aplikasi di HP**: staf bisa memasang Sudaya sebagai aplikasi (ikon di layar HP, terbuka layar penuh) lewat Chrome di Android atau Safari di iPhone. Caranya ada di [Aplikasi Sudaya di HP](APLIKASI_HP.md).

### Menu per peran (satu aplikasi per peran)

Sejak 13 November 2026 setiap peran punya **satu tampilan** dengan menunya sendiri, tanpa menu yang berulang. Isi dan urutan menu **sama persis di laptop dan HP**: di laptop menu kasir ada di bar atas dan menu admin di sidebar; di HP menu kasir menjadi baris kedua di atas layar (bawah layar dipakai keranjang Kasir) dan menu admin dibuka lewat tombol **☰**. Halaman turunan satu menu tampil sebagai **tab** di atas konten.

| Peran | Menu | Tab di dalamnya |
|---|---|---|
| **Kasir** | **Pesanan** · **Kasir** · **Kas** · **Absen** | Pesanan: **Masuk** (pesanan masuk + alarm) \| **Hari ini**; Kas: **Kas** \| **Pengeluaran**; Absen: **Absen** \| **Riwayat** \| **Tugas luar** |
| **Barista** | **Absen** (satu-satunya) | **Absen** \| **Riwayat** \| **Tugas luar** |
| **Owner, Super Admin, Manager, Admin** | **Dasbor** · **Pesanan** · **Keuangan** · **Persetujuan** · **Laporan** · **Menu** · **Stok** · **Karyawan** · **Pengaturan** (sesuai izin) | Keuangan: Kas \| Pengeluaran; Laporan: Penjualan \| Pelanggan; Menu: Menu \| Kategori \| Varian & tambahan; Stok: Stok \| Masuk \| Keluar & waste \| Opname \| Riwayat \| Resep \| Purchase order \| Supplier; Karyawan: Kehadiran \| Rekap absensi \| Data karyawan \| Pengaturan absen; Pengaturan: Outlet & meja \| Konten situs \| Staf \| Peran & izin \| Log aktivitas |

- Akun admin yang juga bertugas di kasir membuka aplikasi kasir dari tombol **Buka aplikasi kasir** di bawah menu admin, dan kembali lewat tombol **Admin** di bar kasir. **Absen saya** juga ada di bawah menu admin.
- Halaman **Beranda staf** yang lama sudah tidak dipakai; alamat lamanya otomatis membuka halaman utama sesuai peran.
- Data **langsung sama di semua perangkat**: pesanan dari meja muncul sendiri di laptop dan HP kasir yang sedang terbuka, dan perubahan status (terima, antar, selesai) ikut ter-update tanpa muat ulang.

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
Menu **Pelanggan**: daftar pelanggan dari pesanan lunas yang mencantumkan nomor HP (checkout QR/kiosk atau delivery): jumlah transaksi, total belanja, rata-rata, transaksi terakhir, dan menu favorit. Nomor HP disamarkan; ketuk ikon mata untuk melihat nomor lengkap. Pesanan yang dibatalkan/direfund tidak dihitung. Poin dan tier pelanggan disembunyikan sampai aturan poinnya diputuskan owner (keputusan 12 November 2026).

### Persetujuan
Pembatalan pesanan yang **sudah dibayar**, koreksi absen staf, **tugas luar**, dan **stock opname** yang ada selisihnya harus disetujui Owner atau Manager (bukan orang yang mengajukan). Foto bukti tugas luar bisa dibuka dari kartunya. Menu **Persetujuan**, lalu pilih **Setujui** (dengan konfirmasi) atau **Tolak**. Uang pesanan yang dibatalkan dikembalikan Manager lewat **Catat refund tunai** di detail pesanan saat uang diserahkan.

### Pesanan dan Kas
Menu **Pesanan** dan **Keuangan → Kas** di area admin hanya untuk memantau. Bayar, batal, refund, dan buka/tutup laci dilakukan dari aplikasi kasir.

### Ekspor
- **Menu → Ekspor ke spreadsheet**: daftar menu, harga, varian, dan ketersediaan per outlet (file `.xlsx`, terbuka di Excel atau Google Sheets).
- **Laporan penjualan → Ekspor ke spreadsheet**: ringkasan, harian, menu terlaris, kategori, per jam, dan metode bayar dalam satu file.
- **Rekap absensi → Ekspor ke spreadsheet**: hari hadir, jam kerja, koreksi, dan sesi tugas luar per karyawan, ditambah sheet **Detail absen** (jam masuk dan pulang tiap sesi) untuk payroll. Keterlambatan belum dihitung karena jadwal shift belum diatur di sistem.

## 3. Super Admin: persiapan sebelum outlet buka

1. **Outlet & meja**: periksa nama dan alamat outlet, lalu tambahkan nomor meja. Pilih **Cetak QR meja** untuk lembar QR siap tempel (satu kartu per meja aktif). Tes satu QR dengan kamera HP sebelum mencetak semua, dan buka lembar QR dari alamat web resmi (bukan alamat uji), karena alamat itu yang tercetak di QR.
2. **Menu**: buat **Kategori**, lalu **Varian & tambahan** (mis. ukuran, gula), lalu **Menu** (foto, harga, varian). Foto JPG, PNG, atau WebP, maksimal 2 MB.
3. **Staf**: undang setiap staf dengan email dan peran. Staf membuat password sendiri lewat tautan undangan.
3a. **QRIS** (halaman outlet → kartu *Pembayaran QRIS*): unggah **gambar QRIS** resmi Sudaya agar pelanggan bisa bayar QRIS. Tanpa gambar QRIS, pilihan QRIS tidak muncul. Antar ke meja **gratis** (biaya antar dihapus 11 November 2026).
3b. **Konten situs** (menu **Konten situs**): isi halaman depan yang bisa dilihat siapa pun tanpa login: tentang Sudaya, dua program (pelatihan kopi, pemberdayaan petani kopi), **info pelatihan** (judul, jadwal, lokasi, tautan daftar), **tautan sedekah Sahabat Berbagi** (tempel tautan program petani kopi), dan kontak footer (WhatsApp, Instagram, email). Kontak hanya tampil bila diisi.
3c. **Menu asli**: 22 menu dari file menu owner sudah **aktif** dengan deskripsi, tetapi harganya belum ada (perintah `php artisan sudaya:menu-asli`). Menu tanpa harga (Rp0) tampil **"Harga menyusul"** di menu pelanggan, landing page, dan kasir, dan **belum bisa dipesan** di kanal mana pun. Di **Menu**, menu seperti ini bertanda **Belum diisi**: klik ikon pensil, isi harga, simpan, dan menu langsung bisa dipesan. Deskripsi juga bisa diubah di form yang sama.
3d. **Americano Series**: Americano punya pilihan **Original, Matcha, Sitrus** (dan Ice/Hot). Tambah varian atau isi selisih harganya di **Varian & tambahan → Americano Series**. Varian di luar daftar bisa diminta pelanggan lewat catatan.
4. **Pengaturan absen**: berdiri di dalam outlet dengan HP atau laptop, pilih **Pakai lokasi perangkat ini**, lalu **Simpan lokasi**. Radius yang disarankan 30 m.
5. **Data karyawan**: tambah karyawan (kode, nama, jabatan, outlet) dan **tautkan ke akun staf** agar bisa absen dari HP.

## 4. Stok (Manager, Admin)

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

Menu **Keuangan → Pengeluaran** (admin) atau **Kas → Pengeluaran** (aplikasi kasir): tanggal, nominal, kategori (parkir, transport, pembelian kecil, perawatan, kebersihan, lainnya), keterangan, dan foto nota. Pengeluaran menunggu persetujuan Owner/Manager di **Persetujuan** (bukan orang yang mencatat). Halaman ini menampilkan total disetujui per bulan dan per kategori; **Laporan penjualan** menampilkan "omzet dikurangi pengeluaran" untuk periode yang sama. Uang yang diambil dari laci kasir tetap dicatat juga di **Kas → Kas keluar**.

## 6. Kasir (pusat pesanan)

Satu pintu masuk untuk semua staf: **/login** (tombol **Masuk** di halaman depan). Setelah login, akun kasir langsung membuka **Pesanan → Masuk** (pesanan masuk), admin membuka **/admin**, dan barista membuka halaman **absen**.

1. **Buka kas** setiap awal shift: menu **Kas**, isi modal awal (uang di laci), lalu **Buka sesi kas**. Pembayaran tunai baru bisa diterima setelah kas dibuka.
2. **Pesanan masuk** (biarkan terbuka di HP/laptop Sudaya): ketuk sekali **"Ketuk untuk mengaktifkan suara"** setiap membuka aplikasi (diulang bila halaman dimuat ulang penuh). Pesanan baru membunyikan **alarm keras berulang**, getar, judul tab berkedip, notifikasi sistem, dan banner merah **PESANAN BARU** sampai kasir menekan **Lihat pesanan**. Alarm juga berbunyi saat kasir sedang di halaman **Kasir, Pesanan, atau Kas** (banner dengan **Lihat pesanan** atau **Nanti**, agar input yang sedang diketik tidak hilang). Layar dijaga tetap menyala. Biarkan aplikasi terbuka (dicas, volume penuh, layar tidak dikunci): bila aplikasi ditutup atau HP terkunci, browser tidak bisa membunyikan alarm. Saran: HP Sudaya untuk Pesanan masuk, laptop untuk Kasir dan cetak struk.
3. Alur tiap pesanan: **Terima pesanan** (baru setelah ini dibuat, jadi tidak ada pesanan dobel) → untuk **antar ke meja**: **Antar sekarang** → **Kirim notif WhatsApp** (1 ketukan: WhatsApp terbuka dengan pesan "sedang diantar ke Lantai … · bagian …" dan, bila tunai, "Mohon siapkan uang tunai Rp…") → **Sudah diantar**. Untuk **ambil sendiri**: **Siap diambil** → **Sudah diambil**. Pelanggan tanpa nomor WhatsApp dikabari lewat halaman status pesanannya (banner, bunyi, notifikasi web).
4. **Bayar saat diantar** (antar ke meja + tunai): kartu bertanda **BELUM BAYAR · Rp…**. Terima uang di meja, ketuk **Terima pembayaran**, isi uang diterima (kembalian otomatis), lalu **Sudah diantar**. Pesanan tidak bisa ditutup sebelum dibayar. **QRIS**: cek dana masuk, lalu **Konfirmasi QRIS diterima** di detail pesanan.
5. **Kasir (POS)** untuk pesanan langsung di counter: ketuk menu, pilih tipe, isi uang diterima, lalu **Bayar tunai & proses**. Nomor antrean dan kembalian tampil besar; struk bisa langsung dicetak.
6. **Batal**: pesanan belum dibayar bisa dibatalkan kasir (stok kembali); yang sudah dibayar butuh persetujuan Owner/Manager, lalu refund tunai dicatat Manager.
7. **Tutup kas** di akhir shift: hitung uang di laci, isi **Uang fisik**, lalu tulis alasan bila ada selisih.

**Struk & printer Bluetooth**: di halaman struk pilih lebar kertas **58 mm** atau **80 mm** (diingat per perangkat), lalu **Cetak**.
- HP Android: pasangkan printer lewat Bluetooth, pasang aplikasi print service printer thermal (mis. **RawBT** dari Play Store), atur printernya di aplikasi itu, lalu di dialog cetak Chrome pilih printer tersebut.
- Laptop Windows: pasangkan printer di **Pengaturan → Bluetooth & perangkat**, pasang driver printer bila diminta, lalu pilih printer itu di dialog cetak.

## 7. Barista

Akun barista untuk **absen** (lihat bagian 8), riwayat & koreksi absen, dan tugas luar. Pesanan tidak diproses dari akun barista: semua pesanan diterima kasir dulu, lalu dibuat, sehingga tidak ada yang dobel. Setelah aplikasi Sudaya dipasang di HP, membukanya langsung ke halaman absen.

## 8. Absen staf (GPS + selfie)

1. Buka aplikasi Sudaya (barista langsung masuk ke halaman absen), atau **Absen** dari beranda staf/menu kasir.
2. **Pertama kali**: daftarkan **foto wajah** (sekali saja): ambil foto di tempat terang, wajah menghadap kamera, centang persetujuan, lalu **Simpan**. Foto ini menjadi pembanding selfie absen yang diperiksa admin di **Kehadiran** (selfie dan foto terdaftar tampil berdampingan). Bila salah foto atau ganti orang, admin bisa **Reset foto wajah** lalu karyawan mendaftar ulang.
3. Ketuk **Absen masuk**. Kamera depan terbuka; ambil selfie.
4. Lokasi dicek otomatis. Bila muncul "Lokasi sesuai", ketuk **Kirim absen masuk**.
5. Saat pulang, lakukan hal yang sama dengan **Absen pulang**. Jam kerja dihitung otomatis.

Bila absen gagal:
- **"Anda di luar area absen"**: pastikan berada di dalam outlet, lalu **Cek lokasi lagi**.
- **"Akurasi GPS … melebihi batas"**: nyalakan lokasi presisi dan pindah ke dekat pintu atau jendela.
- **"Izin lokasi ditolak"**: izinkan lokasi untuk situs ini di pengaturan browser.
- Lupa absen, atau HP mati: **Riwayat & koreksi absen → Ajukan koreksi absen**. Koreksi berlaku setelah disetujui Owner/Manager.

**Tugas luar** (mis. belanja bahan, event, kantor pusat): **Absen → Tugas luar outlet** (atau tile **Tugas luar** di beranda staf). Isi tanggal, jam mulai–selesai, lokasi, tujuan, keterangan, dan foto bukti bila ada, lalu **Ajukan tugas luar**. Setelah disetujui Owner/Manager, absen pada jam tugas boleh dilakukan dari lokasi tugas (di luar radius outlet); selfie dan GPS tetap wajib dan tercatat. Pengajuan yang belum diputuskan bisa dibatalkan sendiri. Tugas luar yang disetujui tampil di halaman **Kehadiran**.

Foto dan lokasi hanya dipakai sebagai bukti kehadiran dan hanya bisa dibuka Owner/Manager. **Foto selfie dan foto bukti tugas luar dihapus otomatis setelah 90 hari**; jam, lokasi absen, dan data pengajuan tetap tersimpan.

## 9. Pelanggan

- **QR meja (meja kantor)**: pelanggan memindai stiker QR di mejanya (dicetak dari **Admin → Outlet & meja → Cetak QR meja**), memilih menu (varian dan catatan seperti *Less sugar*), lalu checkout. **Antar ke meja**: isi **nama**, **department** (mis. Fundraising), **lantai**, **bagian/area meja**, dan **nomor WhatsApp (opsional**, untuk notif saat kopi diantar). **Ambil di counter**: cukup **nama** dan **department**. Permintaan menu di luar daftar ditulis di kolom yang tersedia (harganya dikonfirmasi kasir).
- **Bayar**: **antar ke meja + tunai** → bayar saat pesanan diantar (nomor antrean langsung muncul); **ambil di counter + tunai** → bayar di kasir; atau **QRIS Sudaya** — halaman status menampilkan QRIS dan nominal persis. Setelah kasir mengonfirmasi, halaman berubah sendiri.
- **Notifikasi**: saat kasir menekan **Antar sekarang**, halaman status pesanan menampilkan banner **"Pesananmu sedang diantar ke Lantai … · …"** (plus **"Siapkan uang tunai Rp…"** bila bayar tunai), bunyi, getar, dan notifikasi browser (ketuk **Aktifkan notifikasi** di halaman status). Bila mengisi nomor WhatsApp, kasir juga mengirim pesan WhatsApp.
- **Kiosk**: layar pemesanan mandiri di outlet; pembayaran tetap di kasir.
- QRIS memakai QRIS statis milik outlet dengan konfirmasi manual kasir; QRIS otomatis butuh payment gateway resmi.
- **Halaman depan** (tanpa login): tentang Sudaya, program pelatihan kopi & pemberdayaan petani kopi, info pelatihan, ajakan **sedekah lewat Sahabat Berbagi** (membuka situs Sahabat Berbagi di tab baru), menu, lokasi, dan kontak.

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
