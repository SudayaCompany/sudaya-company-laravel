# UAT operasional outlet Sudaya Coffee

Disusun 4 November 2026. Dijalankan di **staging** (bukan production, bukan development), di perangkat dan jaringan outlet yang sebenarnya, oleh staf dengan peran aslinya. Kolom **Hasil aktual** dan **Status** diisi saat UAT. Status: PASS, FAIL, BLOCKED, NOT TESTED.

Persiapan: data master siap ([MASTER_DATA_PREPARATION.md](MASTER_DATA_PREPARATION.md)), smoke test staging lolos ([LARAVEL_STAGING_RUNBOOK.md](LARAVEL_STAGING_RUNBOOK.md) §10), akun UAT untuk Kasir, Barista, Manager (2 orang), dan Owner, serta uang kas uji yang dihitung.

| No | Skenario | Langkah | Hasil yang diharapkan | Hasil aktual | Status | Penanggung jawab |
|---|---|---|---|---|---|---|
| 1 | Kasir buka sesi kas | Login Kasir → Kas → isi modal awal sesuai uang di laci → Buka sesi kas | "Sesi terbuka", saldo seharusnya = modal. POS tidak lagi menampilkan "Sesi kas belum dibuka" | | | Kasir |
| 2 | Pesanan lewat POS | Kasir → Kasir → pilih produk dengan modifier (mis. Large) → isi tipe pesanan → Bayar tunai & proses dengan uang diterima | Total sesuai harga + modifier, kembalian benar, dialog "Pembayaran berhasil" dengan nomor antrean (mis. SDY-001) | | | Kasir |
| 3 | Pesanan lewat QR meja | Customer scan QR meja → pilih menu → checkout tanpa login | Halaman status "Menunggu pembayaran", kode pesanan 6 karakter, hitung mundur batas bayar, nomor meja terisi | | | Manager (sebagai customer) |
| 4 | Pesanan lewat kiosk | Di perangkat kiosk → pilih menu → checkout (hanya Dine In/Takeaway) | Layar "Pesanan diterima" dengan kode pesanan dan "Menunggu pembayaran", kembali ke menu setelah Selesai | | | Manager |
| 5 | Kasir menerima tunai order QR/kiosk | Kasir → Pesanan → Menunggu bayar → cari kode → Uang pas/isi uang → Konfirmasi | Pesan "Pembayaran dikonfirmasi. Nomor antrean …"; order pindah ke Lunas. Klik konfirmasi dua kali tidak membuat nomor kedua | | | Kasir |
| 6 | KDS menampilkan antrean | Barista membuka KDS di perangkat KDS | Order lunas muncul di kolom New tanpa refresh, urut waktu bayar; order belum dibayar tidak muncul; modifier, catatan, nomor meja terbaca jelas dari jarak kerja barista | | | Barista |
| 7 | Barista mengubah status | Start Preparing → Mark Ready → Complete | Kartu pindah kolom; waktu tunggu berwarna kuning ≥5 menit, merah ≥10 menit | | | Barista |
| 8 | Customer menerima pembaruan status | Customer QR membiarkan halaman status terbuka selama langkah 5–7 | Berubah sendiri: Pesanan diterima → Sedang dibuat → Siap diambil → Selesai, tanpa refresh | | | Manager |
| 9 | Dua kasir bersamaan | Dua perangkat kasir membayar dua order dalam detik yang sama | Dua nomor antrean berbeda dan berurutan | | | Kasir + Manager |
| 10 | Kas masuk/keluar | Manager/Kasir → Kas → Kas keluar Rp tertentu dengan alasan (mis. beli es batu) | Saldo seharusnya berkurang; tercatat di pergerakan kas dengan nama | | | Kasir |
| 11 | Kasir menutup sesi kas | Hitung uang laci → Tutup sesi → isi uang fisik | Bila sama: "Uang cocok". Bila beda: wajib alasan, selisih tercatat di riwayat | | | Kasir |
| 12 | Manager mencocokkan uang fisik | Manager membandingkan riwayat sesi (seharusnya vs fisik) dan daftar Pesanan → Lunas hari itu | Total penjualan tunai = jumlah order lunas tunai hari itu dikurangi refund | | | Manager |
| 13 | Pembatalan order belum dibayar | Barista/Manager → detail order belum dibayar → Batalkan dengan alasan | Order "Dibatalkan", tidak bisa dibayar lagi | | | Barista |
| 14 | Pembatalan order lunas + refund | Barista ajukan pembatalan → Manager **lain** setujui → Manager catat refund setelah uang diserahkan | Pemohon tidak melihat tombol Setujui. Setelah refund: status Dikembalikan, kas keluar tercatat, struk bertanda DIREFUND. Refund kedua ditolak | | | Barista + Manager |
| 15 | Hak akses | Kasir buka KDS; Barista buka Kas; Barista coba konfirmasi tunai | Ditolak (403 atau pesan izin) | | | Manager |
| 16 | Sold out manual | Admin/Manager tandai produk sold out → customer dan POS mencoba memesan | Produk tampil "Sold out", tidak bisa ditambahkan; order lama yang memuatnya ditolak saat checkout | | | Manager |
| 17 | Struk | Cetak struk order lunas dari detail order di printer outlet | Muat di kertas 80 mm: nomor antrean, item + modifier, total, metode, diterima, kembali, kasir, waktu | | | Kasir |
| 18 | Internet lambat | Throttle/hotspot lemah di perangkat kasir → simpan pesanan | Tombol menunjukkan proses; tidak ada pesanan ganda | | | Manager |
| 19 | Internet terputus saat simpan | Putuskan Wi-Fi tepat setelah tekan Simpan → sambung lagi → tekan Simpan lagi | Pesan "Koneksi terputus"; setelah dikirim ulang hanya ada **satu** pesanan (cek daftar Pesanan) | | | Manager |
| 20 | Realtime terputus | Putuskan internet perangkat KDS 1 menit → sambung | Indikator berubah "Polling"/"Menyambung", order baru tetap muncul setelah tersambung | | | Barista |
| 21 | Order tidak dibayar kedaluwarsa | Buat order QR, jangan bayar, tunggu lewat batas waktu outlet | Status customer "Dibatalkan" dengan alasan batas waktu; kasir tidak bisa membayarnya | | | Manager |
| 22 | Customer kiosk mendapat nomor antrean | Customer kiosk menyebut kode referensi ke kasir → kasir menerima uang dan mencetak struk | Struk memuat nomor antrean dan kode referensi; customer menunggu nomornya dipanggil (keputusan sementara EXT-012) | | | Kasir |
| 23 | QR meja tidak valid | Buka URL QR dengan kode outlet salah; lalu dengan nomor meja berformat salah (mis. spasi/simbol) | Outlet salah: "Outlet tidak ditemukan". Nomor meja berformat salah ditolak saat checkout. (Nomor meja di luar daftar resmi belum ditolak sampai master meja dibuat) | | | Manager |
| 24 | Pembatalan saat hanya satu Manager bertugas | Barista mengajukan pembatalan order lunas → Manager bertugas menyetujui | Berhasil tanpa self-approval; bila Manager sendiri yang mengajukan, tombol Setujui tidak muncul untuknya | | | Manager |

## Penerimaan

UAT dianggap lolos bila skenario 1–24 PASS, atau defect yang tersisa disetujui owner sebagai bukan penghalang soft launch.

| Peran | Nama | Tanda tangan | Tanggal |
|---|---|---|---|
| Manager outlet | | | |
| Owner | | | |
