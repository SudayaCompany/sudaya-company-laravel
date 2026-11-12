# Panduan Super Admin: perawatan Sudaya Coffee setelah live

Untuk Super Admin yang memegang aplikasi dan server setelah serah terima. Cara memakai aplikasi per peran ada di [PANDUAN_PENGGUNA.md](PANDUAN_PENGGUNA.md). Pemasangan server, rilis, dan restore ada di [DEPLOY_VPS.md](DEPLOY_VPS.md).

## 1. Akses yang harus Anda pegang

Simpan semuanya di password manager, bukan di catatan HP atau chat.

| Akses | Untuk apa |
|---|---|
| Akun penyedia VPS + IP server + kunci SSH | Masuk server, perpanjang langganan, lihat tagihan |
| Akun pengelola domain | Perpanjang domain, ubah DNS |
| Akun Supabase (pemilik project production) | Database, login staf, foto; cek pemakaian dan paket |
| Akun GitHub `SudayaCompany` | Kode aplikasi; atur siapa yang boleh push ke `main` |
| Akun Super Admin aplikasi | Semua menu Admin |
| Isi `/var/www/sudaya/shared/.env` | Kunci rahasia aplikasi (jangan pernah dibagikan) |

Perpanjangan VPS dan domain yang terlewat membuat web mati. Pasang pengingat sebulan sebelum jatuh tempo.

## 2. Rutinitas

### Harian (±2 menit, dari HP/laptop)

- **Dasbor**: omzet dan jumlah pesanan hari ini wajar.
- **Persetujuan**: tidak ada permintaan batal/refund yang menggantung.
- **Kas**: sesi kas kemarin sudah ditutup; selisih (bila ada) punya alasan.

### Mingguan (±10 menit)

1. Cek kesehatan server: `sudo sudaya-artisan sudaya:doctor` → semua ✓. Arti tiap baris dan perbaikannya ada di [DEPLOY_VPS.md §Bila ada masalah](DEPLOY_VPS.md#bila-ada-masalah).
2. Salin backup terbaru ke luar VPS (laptop atau penyimpanan kantor):
   `scp <user>@<ip-vps>:/var/backups/sudaya/sudaya-<tanggal>.dump .`
3. **Log aktivitas**: cari yang tidak wajar, mis. login gagal berulang, perubahan harga atau peran yang tidak Anda kenal.
4. **Rekap absensi**: cek koreksi absen yang menunggu.

### Bulanan (±30 menit, jam sepi)

1. Update keamanan server (update kritis sudah otomatis, ini untuk sisanya):
   ```sh
   sudo apt-get update && sudo apt-get upgrade -y
   [ -f /var/run/reboot-required ] && sudo reboot
   ```
   Setelah reboot: `sudo sudaya-artisan sudaya:doctor`.
2. **Staf**: nonaktifkan akun karyawan yang sudah keluar; pastikan peran masih sesuai tugasnya.
3. Supabase Dashboard → **Usage**: ukuran database, storage, dan bandwidth masih di bawah batas paket. `sudaya:doctor` memberi peringatan bila database > 400 MB.
4. Ruang disk server: baris **Ruang disk** di `sudaya:doctor`.

### Tiap 3 bulan (disarankan)

Latih restore ke project Supabase sementara (urutan [DEPLOY_VPS.md §Restore](DEPLOY_VPS.md#restore-darurat-project-supabase-rusakterhapus), di server/laptop uji, bukan di production), lalu hapus project sementara itu. Backup yang tidak pernah dicoba belum terbukti bisa dipakai.

## 3. Tugas umum di aplikasi

| Kebutuhan | Menu | Catatan |
|---|---|---|
| Karyawan baru | **Staf → Undang staf** | Pilih peran dan outlet. Salin **tautan undangan** dan kirim lewat WhatsApp; karyawan membuat password sendiri. Tautan sekali pakai |
| Karyawan lupa password | **Staf** → ikon kunci di baris karyawan | Salin **tautan reset password**, kirim lewat WhatsApp |
| Karyawan keluar | **Staf** → nonaktifkan | Langsung ter-logout di semua perangkat dan tidak bisa login lagi. Riwayat transaksi dan absennya tetap tersimpan |
| Ubah izin suatu peran | **Peran & izin** | Hati-hati: berlaku untuk semua karyawan dengan peran itu. Tercatat di Log aktivitas |
| Menu baru / ubah harga, foto, deskripsi | **Menu** | Harga dihitung ulang di server; perubahan langsung tampil ke pelanggan |
| Menu habis hari ini | **Menu → Tandai habis** | Pelanggan tidak bisa memesannya sampai ditandai tersedia lagi |
| Ganti gambar QRIS | **Outlet & meja → Pembayaran QRIS** | Pastikan gambar QRIS resmi milik Sudaya; pembayaran QRIS dikonfirmasi manual oleh barista/kasir |
| Tambah meja / cetak QR meja | **Outlet & meja** | QR meja yang tidak terdaftar ditolak saat checkout |
| Lokasi & radius absen | **Pengaturan absen** | Radius awal 30 m; foto absen terhapus otomatis setelah 90 hari |
| Isi halaman depan publik | **Konten situs** | Tentang, program, info pelatihan, tautan sedekah, kontak WA/IG/email |
| Laporan penjualan / ekspor | **Laporan penjualan** | Ekspor Excel per periode |
| Rekap absensi untuk gaji | **Rekap absensi** | Ekspor Excel |
| Siapa mengubah apa | **Log aktivitas** | Tidak bisa diubah atau dihapus siapa pun |

## 4. Memperbarui aplikasi

1. Perubahan kode dibuat developer dan masuk ke branch `main` di GitHub **dengan persetujuan Anda** (atur branch protection dari akun `SudayaCompany` agar tidak ada yang push langsung).
2. Di server, jam sepi: `sudo sudaya-deploy`.
3. Bila deploy berhenti karena ada migration baru: minta developer menjelaskan isinya, lalu `sudo sudaya-deploy --migrate`.
4. Bila ada yang aneh setelah rilis: `sudo sudaya-rollback`, lalu kabari developer.

Detail: [DEPLOY_VPS.md §Rilis versi baru](DEPLOY_VPS.md#rilis-versi-baru).

## 5. Jangan dilakukan

- **Jangan mengganti `APP_KEY`** di `.env`: semua staf ter-logout dan pelanggan kehilangan akses ke halaman status pesanan yang sedang berjalan.
- **Jangan menghapus** `/var/www/sudaya/shared/storage/framework/auth-tokens`: semua staf ter-logout.
- **Jangan mengubah data langsung di Supabase Table Editor** kecuali darurat bersama developer: perubahan itu melewati aturan bisnis (harga, stok, kas) dan tidak tercatat di Log aktivitas.
- **Jangan menambah policy RLS atau membuat bucket menjadi publik** di Supabase. Data hanya boleh diakses lewat server aplikasi; foto absen wajib privat.
- **Jangan membagikan** service role key, password database, isi `.env`, atau file backup. Bila bocor, ganti kuncinya di Supabase dan perbarui `.env` ([DEPLOY_VPS.md §Keamanan server](DEPLOY_VPS.md#keamanan-server)).
- **Jangan commit** `.env`, file backup, atau kunci ke GitHub: repo `SudayaCompany/sudaya-company-laravel` **publik**.
- **Jangan memberi peran Super Admin ke banyak orang.** Cukup Anda dan satu cadangan tepercaya.
- Perintah yang menghapus database (`migrate:fresh`, `migrate:rollback`, `db:wipe`, dan sejenisnya) sudah diblokir di production. Jangan mencoba mengakalinya.

## 6. Saat ada masalah

1. Jalankan `sudo sudaya-artisan sudaya:doctor`. Baris ✗ menunjukkan bagian yang rusak beserta petunjuk perbaikannya.
2. Cocokkan gejala di [DEPLOY_VPS.md §Bila ada masalah](DEPLOY_VPS.md#bila-ada-masalah).
3. Masalah setelah rilis baru: `sudo sudaya-rollback`.
4. Data hilang atau project Supabase rusak: [DEPLOY_VPS.md §Restore](DEPLOY_VPS.md#restore-darurat-project-supabase-rusakterhapus).

Kontak eskalasi (isi sendiri):

| Untuk | Nama | Kontak |
|---|---|---|
| Developer aplikasi | | |
| Penyedia VPS (support) | | |
| Cadangan Super Admin | | |
