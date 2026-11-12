# Deploy ke VPS: dari server kosong sampai web live

Untuk Super Admin/pengelola teknis Sudaya Coffee. Panduan ini memakai paket di folder [`deploy/`](../deploy/): satu VPS Ubuntu (Nginx + PHP-FPM) dan satu project Supabase. Semua perintah bisa disalin apa adanya; ganti bagian `<...>`.

Perawatan rutin setelah web live ada di [PANDUAN_SUPERADMIN.md](PANDUAN_SUPERADMIN.md).

## Gambaran

```
Pelanggan / staf (HP, tablet, laptop)
        │ https://<domain>
        ▼
VPS Ubuntu (Singapura/Jakarta) ── Nginx → PHP-FPM → aplikasi Laravel (/var/www/sudaya/current)
        │                                   cron: scheduler Laravel (backup 02.30, hapus foto absen > 90 hari)
        ▼
Supabase (Singapura): database PostgreSQL, login staf (Auth), foto (Storage), sinyal realtime
```

## Yang perlu disiapkan

| Butir | Rekomendasi | Catatan |
|---|---|---|
| VPS | 2 vCPU, RAM 2 GB (lebih nyaman 4 GB), SSD 40 GB, **Ubuntu 24.04 LTS** tanpa panel (cPanel/aaPanel tidak perlu) | Region **Singapura** (satu region dengan Supabase) atau Jakarta. Akses SSH root/sudo |
| Domain | Mis. `sudayacoffee.com` / `.id` | Akses ke pengaturan DNS |
| Project Supabase | Region **Southeast Asia (Singapore)** | Paket Free: database 500 MB, tanpa backup otomatis, project bisa *paused* bila tidak ada aktivitas seminggu. Paket Pro: backup harian otomatis dan tidak pernah *paused*. Cek harga terbaru di halaman Supabase sebelum memilih |
| Password manager | Menyimpan password database, kunci Supabase, `APP_KEY` | Jangan kirim kunci lewat chat atau commit ke Git (repo GitHub ini **publik**) |

Undangan staf dan reset password tidak memakai email: aplikasi membuat **tautan sekali pakai** yang disalin admin dan dikirim lewat WhatsApp. Jadi SMTP tidak perlu diatur.

## 1. Project Supabase production

1. Supabase Dashboard → **New project** → region **Southeast Asia (Singapore)**. Buat password database yang kuat dan simpan di password manager.
2. **Database → Extensions** → aktifkan **pg_cron**.
3. **Settings → JWT Keys**: pastikan memakai *JWT signing keys* (ES256). Project baru sudah memakainya; tanpa itu login staf gagal.
4. **Authentication → Sign In / Providers**: matikan **Allow new users to sign up** (akun hanya lewat undangan dari aplikasi).
5. **Authentication → URL Configuration**: Site URL = `https://<domain>`.
6. Catat untuk langkah 4:
   - **Connect → Session pooler** (port 5432): `postgresql://postgres.<ref>:<password>@aws-<n>-ap-southeast-1.pooler.supabase.com:5432/postgres?sslmode=require`. Password berkarakter khusus (`@ : / ? #`) wajib di-URL-encode.
   - **Settings → API**: Project URL, *publishable key*, dan *secret/service role key*.

Jangan memakai project development untuk production, dan jangan menyalin data development ke production.

## 2. DNS

Di pengelola domain, buat record **A** `@` dan `www` ke IP VPS. Tunggu sampai `ping <domain>` menunjukkan IP VPS (biasanya beberapa menit, bisa sampai beberapa jam).

## 3. Siapkan server (sekali)

Login SSH ke VPS sebagai user dengan sudo, lalu:

```sh
sudo apt-get update && sudo apt-get install -y git
git clone https://github.com/SudayaCompany/sudaya-company-laravel.git ~/sudaya
sudo DOMAIN=<domain> bash ~/sudaya/deploy/scripts/provision.sh
```

Skrip ini (aman diulang) memasang Nginx, PHP 8.5, PostgreSQL client 17, Node.js 22, Composer, certbot, firewall (hanya SSH/HTTP/HTTPS), update keamanan otomatis, user `sudaya`, folder `/var/www/sudaya`, file `.env` production dengan `APP_KEY` baru, cron Laravel, dan tiga perintah:

| Perintah | Fungsi |
|---|---|
| `sudo sudaya-deploy` | Rilis versi terbaru dari GitHub tanpa mengganggu outlet |
| `sudo sudaya-rollback` | Kembali ke rilis sebelumnya |
| `sudo sudaya-artisan <perintah>` | Menjalankan `php artisan` aplikasi, mis. `sudo sudaya-artisan sudaya:doctor` |

## 4. Isi `.env`

```sh
sudo nano /var/www/sudaya/shared/.env
```

Isi `DB_URL`, `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, `SUPABASE_SERVICE_ROLE_KEY` dari langkah 1. Nilai lain sudah benar untuk production (lihat [`deploy/env.production.example`](../deploy/env.production.example)). Jangan mengganti `APP_KEY` setelah web dipakai: semua staf akan ter-logout.

## 5. Deploy pertama dan pasang database

```sh
sudo sudaya-deploy --first
```

Yang terjadi: kode diunduh dari GitHub → Composer → build tampilan → **schema database dipasang dari nol** (`migrate`: 47 tabel, RLS, fungsi, 2 job pg_cron) → data awal (company, brand, outlet SDY01, 6 peran, 41 izin) → bucket foto Supabase Storage → rilis diaktifkan → dicek `/up` dan `/api/health`. Pemasangan schema hanya berjalan di database kosong; project yang setengah jadi atau bukan Supabase ditolak tanpa perubahan.

## 6. HTTPS

```sh
sudo certbot --nginx -d <domain> -d www.<domain> --redirect -m <email-admin>
```

Sertifikat diperpanjang otomatis oleh certbot. Cek: buka `https://<domain>`, ikon gembok tampil, dan `http://` dialihkan ke `https://`.

## 7. Super Admin pertama

1. Supabase Dashboard → **Authentication → Users → Add user → Create new user**: email Super Admin, password kuat, centang *Auto Confirm User*.
2. Di server:

```sh
sudo sudaya-artisan sudaya:admin-bootstrap --email=<email> --name="<nama lengkap>"
```

Perintah ini hanya bisa sekali: Super Admin berikutnya diundang dari aplikasi (Admin → Staf).

## 8. Data asli

```sh
sudo sudaya-artisan sudaya:menu-asli
```

22 menu dari file owner masuk **aktif** dengan deskripsi dan kategori berurutan, tampil **"Harga menyusul"** (belum bisa dipesan) sampai harganya diisi. Harga bisa diisi admin satu per satu di Admin → Menu, atau dimasukkan ke data perintah ini (kolom `price` di `app/Console/Commands/RealMenuCommand.php`) lalu `sudo sudaya-artisan sudaya:menu-asli --sync`; harga yang sudah diisi admin tidak pernah ditimpa. Lalu login di `https://<domain>/login` dan isi lewat Admin (urutan di [PANDUAN_PENGGUNA.md](PANDUAN_PENGGUNA.md) §Persiapan):

1. **Outlet**: nama, alamat, koordinat & radius absen, daftar meja, gambar QRIS.
2. **Menu**: isi harga menu bertanda **Belum diisi** (ikon pensil), foto, dan sesuaikan deskripsi bila perlu.
3. **Konten situs**: kontak WhatsApp/Instagram/email, info pelatihan, tautan program Sahabat Berbagi.
4. **Staf**: undang tiap karyawan dengan perannya; kirim tautan undangan lewat WhatsApp. Susunan akun awal (keputusan owner 12 November 2026): **Admin** untuk pilar, **Kasir** untuk HP/laptop milik Sudaya (pusat pesanan), dan **2 Barista** (absen saja). Barista mendaftarkan foto wajah saat pertama membuka absen.
5. Daftarkan meja di **Admin → Outlet & meja**, lalu **Cetak QR meja** dan tempel stiker di tiap meja.
6. Di HP/laptop kasir: login akun Kasir, pasang aplikasi Sudaya, buka **Pesanan masuk**, ketuk **aktifkan suara & notifikasi**, dan pasangkan printer struk Bluetooth ([PANDUAN_PENGGUNA.md](PANDUAN_PENGGUNA.md) §Kasir).

## 9. Cek kesehatan dan uji

```sh
sudo sudaya-artisan sudaya:doctor
```

Semua baris harus ✓. Peringatan "Cron Laravel" hilang sendiri ±5 menit setelah deploy; "Backup database" hilang setelah backup pertama (02.30 WIB, atau jalankan `sudo sudaya-artisan sudaya:backup` sekarang). Lalu jalankan skenario UAT di [SUDAYA_OUTLET_UAT.md](SUDAYA_OUTLET_UAT.md) di perangkat outlet, dan pasang aplikasi staf di HP ([APLIKASI_HP.md](APLIKASI_HP.md)).

Disarankan memasang uptime monitor gratis (mis. UptimeRobot) ke `https://<domain>/api/health` tiap 5 menit: Anda dapat notifikasi bila web atau database mati, dan project Supabase paket Free tidak dianggap tidak aktif.

Opsional: daftarkan domain di Google Search Console dan kirim `https://<domain>/sitemap.xml` agar halaman depan dan menu cepat muncul di pencarian. Tautan web yang dibagikan di WhatsApp/Instagram otomatis menampilkan judul, deskripsi (dari "tentang" di Konten situs), dan foto program.

## Rilis versi baru

Setelah perubahan kode di-push ke branch `main` GitHub:

```sh
sudo sudaya-deploy
```

- Bila ada migration baru, deploy **berhenti sebelum mengubah apa pun** dan menampilkan nama file-nya. Baca isinya (`database/migrations/<nama>.php` di GitHub), lalu `sudo sudaya-deploy --migrate`.
- Rilis tertentu: `sudo sudaya-deploy --ref <commit-atau-tag>`.
- Bila rilis baru tidak sehat (`/up` atau `/api/health` gagal), deploy otomatis kembali ke rilis sebelumnya.
- Lima rilis terakhir disimpan di `/var/www/sudaya/releases`. Staf tidak ter-logout karena sesi disimpan di folder bersama.
- Rilis sebaiknya di jam sepi.

## Rollback

```sh
sudo sudaya-rollback              # ke rilis sebelumnya
sudo sudaya-rollback <nama-rilis> # ke rilis tertentu: ls /var/www/sudaya/releases
```

Hanya kode yang kembali. Migration selalu aditif (menambah, tidak menghapus), jadi tidak perlu dibalik.

## Backup

- Otomatis tiap hari 02.30 WIB ke `/var/backups/sudaya` (disimpan 14 hari): data bisnis dan akun login staf, tanpa token sesi. Log: `/var/www/sudaya/shared/storage/logs/backup.log`.
- Manual: `sudo sudaya-artisan sudaya:backup`.
- **Simpan salinan di luar VPS** minimal seminggu sekali (bila VPS hilang, backup di dalamnya ikut hilang). Dari laptop:

```sh
scp <user>@<ip-vps>:/var/backups/sudaya/sudaya-<tanggal>.dump .
```

File backup berisi data pribadi pelanggan dan staf: simpan di tempat terkunci, jangan dibagikan.

Foto (menu, absen, QRIS) ada di Supabase Storage dan **tidak** ikut file backup. Foto absen memang dihapus otomatis setelah 90 hari; foto menu dan QRIS bisa diunggah ulang dari Admin.

## Restore (darurat: project Supabase rusak/terhapus)

Restore hanya ke **project Supabase baru yang kosong**. Perintahnya menolak database yang sudah berisi data, jadi tidak bisa menimpa production yang sedang jalan.

1. Buat project Supabase baru (langkah 1), ganti `DB_URL` dan `SUPABASE_*` di `.env`.
2. Pasang schema saja, tanpa data awal:

```sh
sudo sudaya-artisan optimize:clear
sudo sudaya-artisan migrate --force
```

3. Pulihkan backup dan bucket foto, lalu aktifkan konfigurasi:

```sh
sudo sudaya-artisan sudaya:restore /var/backups/sudaya/sudaya-<tanggal>.dump
sudo sudaya-artisan sudaya:storage-setup
sudo sudaya-artisan optimize
sudo systemctl reload php8.5-fpm
sudo sudaya-artisan sudaya:doctor
```

Restore berjalan dalam satu transaksi: bila gagal, tidak ada data yang masuk. Staf login ulang dengan password lama. Unggah ulang foto menu dan QRIS bila perlu. Diuji 12 November 2026: backup → project kosong kedua → jumlah baris semua tabel sama, Super Admin bisa dipakai lagi.

## Bila ada masalah

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| Halaman "502 Bad Gateway" | PHP-FPM mati | `sudo systemctl restart php8.5-fpm`, lalu `sudo sudaya-artisan sudaya:doctor` |
| "Terjadi kesalahan" / error 500 | Lihat log | `sudo tail -n 50 /var/www/sudaya/shared/storage/logs/laravel-$(date +%F).log` |
| Semua halaman error setelah rilis | Rilis bermasalah | `sudo sudaya-rollback` |
| `/api/health` 503 | Database Supabase tidak terjangkau / project *paused* | Cek status project di Supabase Dashboard; *restore project* bila paused |
| Login staf gagal semua | Kunci JWT bukan asimetris, atau `SUPABASE_URL` salah | `sudaya:doctor` baris **Login (JWKS)** |
| Pesanan QR tidak pernah kedaluwarsa | Job pg_cron gagal | `sudaya:doctor` baris **Kedaluwarsa pesanan** |
| Foto tidak bisa diunggah | Bucket belum ada / kunci service role salah | `sudo sudaya-artisan sudaya:storage-setup` |
| Peringatan "Cron Laravel" | Cron mati | `sudo systemctl restart cron`; cek `/etc/cron.d/sudaya` |
| Disk hampir penuh | Log/backup menumpuk | `sudo du -sh /var/backups/sudaya /var/www/sudaya/shared/storage/logs`; hapus file lama |
| Sertifikat HTTPS kedaluwarsa | Perpanjangan otomatis gagal | `sudo certbot renew` |
| Ingin menutup sementara (perawatan) | | `sudo sudaya-artisan down` lalu `sudo sudaya-artisan up` |

## Keamanan server

- Login SSH memakai kunci (SSH key), lalu matikan login password (`PasswordAuthentication no` di `/etc/ssh/sshd_config`).
- `.env` hanya bisa dibaca root dan user `sudaya` (640). Bila kunci bocor: buat ulang di Supabase (service role/password database), ganti di `.env`, `sudo sudaya-artisan optimize`, reload PHP-FPM.
- Repo GitHub **publik**: jangan pernah commit `.env`, file backup, atau kunci apa pun.
- Folder `tests/` tidak ikut ke server (test otomatis tidak bisa berjalan di production).
