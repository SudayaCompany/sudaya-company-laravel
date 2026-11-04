# Rencana hosting staging Laravel

Disusun 4 November 2026 untuk keputusan owner (EXT-029). Dokumen ini melengkapi [LARAVEL_HOSTING_REQUIREMENTS.md](LARAVEL_HOSTING_REQUIREMENTS.md). Harga tidak dicantumkan karena belum ada penawaran yang diperiksa. Setiap penyedia harus dimintai harga dan diuji kelayakannya sebelum dibayar.

## Tujuan staging

Staging adalah salinan production yang dipakai untuk UAT outlet dan latihan deploy/rollback. Lingkungannya:
- satu server aplikasi;
- satu project Supabase staging di Singapura (lihat [SUPABASE_STAGING_PLAN.md](SUPABASE_STAGING_PLAN.md));
- domain HTTPS;
- data uji yang bukan data asli.

Pilihan hosting staging sebaiknya sama dengan production nanti, supaya hasil UAT dan pengukuran latensi berlaku untuk production.

## Kebutuhan minimum

| Kebutuhan | Nilai untuk Sudaya | Catatan |
|---|---|---|
| Laravel | 13 | Sudah dipakai di repo |
| PHP | 8.3 ke atas, disarankan 8.5 (versi yang dipakai development) | Ekstensi Laravel standar + `pdo_pgsql`, `intl`, `sodium`, `zip`, `curl` dengan CA bundle terbaru |
| Web server | Nginx atau Apache, document root `public/` | Satu domain atau subdomain, mis. `staging.<domain>` |
| Proses PHP | PHP-FPM dengan beberapa worker | Server bawaan `php -S` tidak boleh dipakai: satu proses, terbukti mengantre saat QA (±7,4 detik per request dari laptop) |
| Frontend | Aset statis hasil `npm run build` (`public/build`) | Node.js tidak dibutuhkan di server bila build di CI/mesin lain |
| Database | PostgreSQL Supabase eksternal lewat pooler, `sslmode=require` | Koneksi keluar TCP 5432 (session pooler) atau 6543 (transaction pooler) wajib terbuka |
| Koneksi keluar lain | HTTPS 443 ke `*.supabase.co` (Auth, Storage) | Browser juga butuh WebSocket ke Supabase Realtime; ini dari perangkat outlet, bukan dari server |
| HTTPS | Wajib, sertifikat otomatis (mis. Let's Encrypt) | Cookie `Secure`, HSTS, kamera/GPS (M7) |
| Composer | Di server, atau `vendor/` dibangun di CI | `composer install --no-dev --optimize-autoloader` |
| SSH | Wajib | Menjalankan `php artisan optimize`, bootstrap admin, membaca log |
| Cron | Opsional untuk M1–M4 | Kedaluwarsa order dan pembersihan rate limit berjalan di `pg_cron` database, bukan cron server. Sediakan cron bila nanti ada `schedule:run` |
| Queue | Tidak diperlukan sekarang | `QUEUE_CONNECTION=sync` |
| Penyimpanan tulis | `storage/` dan `bootstrap/cache`, dibagi antar rilis | Sesi dan store token berupa file |
| Logging | `storage/logs` harian, bisa dibaca lewat SSH; idealnya dikirim ke layanan log/monitoring | Log aplikasi tidak memuat nilai parameter query (M1) |
| Deploy/rollback | Rilis berbasis direktori + symlink `current`, atau fitur bawaan penyedia | Rollback = kembalikan symlink/rilis sebelumnya |
| Region | Singapura, sama dengan Supabase staging | Latensi database menentukan kecepatan setiap halaman |

## Perbandingan opsi

| Aspek | Shared hosting Laravel-compatible | Managed Laravel (mis. Laravel Cloud, atau Forge + server) | VPS kecil di Singapura |
|---|---|---|---|
| PHP 8.3+ dan `pdo_pgsql` | Harus dicek per paket | Ya | Ya, dipasang sendiri |
| Koneksi keluar ke Postgres Supabase | Sering diblokir; harus diuji sebelum bayar | Ya | Ya |
| PHP-FPM multi-worker | Tergantung paket | Ya | Ya |
| SSH, Composer | Sebagian paket | Ya | Ya |
| HTTPS otomatis | Biasanya ada | Ya | Diatur sendiri (Certbot) |
| Deploy dari Git dan rollback | Umumnya manual | Bawaan | Skrip sendiri atau alat deploy |
| Pengelolaan keamanan server (patch OS, firewall) | Penyedia | Penyedia (Cloud) atau tim (Forge + VPS) | Tim sendiri |
| Kompleksitas untuk tim kecil | Rendah, tapi risiko teknis tinggi | Paling rendah | Paling tinggi |
| Biaya operasional | Biasanya paling murah; **harga belum dicek** | Biasanya paling mahal; **harga belum dicek** | Menengah; **harga belum dicek**, ditambah waktu tim |
| Risiko utama | Port database diblokir, versi PHP tertinggal, tidak ada FPM worker | Biaya bulanan, lokasi data perlu dicek | Server tidak dipatch, backup server terlupa |

## Rekomendasi

1. **Pilihan utama untuk soft launch: managed Laravel di region Singapura.** Opsi ini memenuhi semua kebutuhan teknis tanpa pengecualian dan paling sedikit menambah beban tim yang sedang mempersiapkan outlet. Deploy dan rollback sudah bawaan.
2. **Alternatif lebih hemat: VPS kecil di Singapura**, bila ada orang yang bertanggung jawab atas patch OS, firewall, dan sertifikat. Bila memakai alat seperti Forge, beban pengelolaannya mendekati managed.
3. **Shared hosting hanya bila lolos uji kelayakan** sebelum pembayaran penuh. Uji itu ada di [LARAVEL_HOSTING_REQUIREMENTS.md](LARAVEL_HOSTING_REQUIREMENTS.md) §Rekomendasi: PHP 8.3+, `pdo_pgsql`, query ke pooler Supabase dari server, HTTPS ke Supabase, SSH dan Composer. Tambahkan juga PHP-FPM multi-worker.
4. Apa pun pilihannya, **staging dan production memakai penyedia dan region yang sama**.

Keputusan biaya ada di owner. Yang perlu dikumpulkan sebelum memutuskan: penawaran harga tertulis dari 2–3 penyedia, hasil uji kelayakan, dan siapa yang bertanggung jawab atas server.

## Yang dibutuhkan dari owner

| No | Keputusan/aksi | Status |
|---|---|---|
| 1 | Pilih jenis hosting dan penyedia (EXT-029) | Belum |
| 2 | Domain/subdomain staging dan production (EXT-021) | Belum |
| 3 | Akun penyedia dan metode pembayaran, oleh owner | Belum |
| 4 | Orang yang memegang akses server/panel dan menerima notifikasi | Belum |
