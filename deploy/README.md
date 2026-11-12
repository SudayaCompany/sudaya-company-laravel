# Paket deploy VPS

Langkah lengkap ada di [docs/DEPLOY_VPS.md](../docs/DEPLOY_VPS.md); perawatan rutin di [docs/PANDUAN_SUPERADMIN.md](../docs/PANDUAN_SUPERADMIN.md).

| File | Dipasang ke | Isi |
|---|---|---|
| `scripts/provision.sh` | dijalankan sekali | Persiapan Ubuntu 24.04/26.04: Nginx, PHP 8.5, PostgreSQL client 17, Node 22, Composer, certbot, firewall, user `sudaya`, `.env`, cron |
| `scripts/deploy.sh` | `/usr/local/sbin/sudaya-deploy` | Rilis tanpa downtime + cek kesehatan + kembali otomatis bila gagal |
| `scripts/rollback.sh` | `/usr/local/sbin/sudaya-rollback` | Kembali ke rilis sebelumnya |
| `nginx/sudaya.conf` | `/etc/nginx/sites-available/sudaya` | Server block (HTTPS ditambahkan certbot) |
| `php/sudaya-fpm.conf` | `/etc/php/<versi>/fpm/pool.d/sudaya.conf` | Pool PHP-FPM user `sudaya` |
| `php/99-sudaya.ini` | `/etc/php/<versi>/{fpm,cli}/conf.d/` | Pengaturan PHP production |
| `cron.sudaya` | `/etc/cron.d/sudaya` | `schedule:run` tiap menit (backup 02.30 WIB, hapus foto absen > 90 hari) |
| `env.production.example` | `/var/www/sudaya/shared/.env` | Template `.env` production tanpa rahasia |

Diuji 12 November 2026 di container Ubuntu 24.04 + Supabase Postgres 17 lokal: provision, deploy pertama (`--first`), deploy ulang, penjaga migration, perintah perusak ditolak, rollback otomatis saat rilis rusak, `sudaya-rollback`, backup, dan `sudaya:doctor` 36/36.
