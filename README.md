# Sudaya Coffee

Aplikasi web Sudaya Company untuk kedai Sudaya Coffee:

- **Halaman depan publik**: tentang Sudaya, program pelatihan kopi dan pemberdayaan petani kopi, info pelatihan, tautan sedekah Sahabat Berbagi, dan kontak.
- **Pelanggan**: pesan lewat QR meja/web/kiosk, bayar tunai atau QRIS, lalu pantau status pesanan.
- **Staf** (bisa dipasang sebagai aplikasi di HP): kasir/POS, layar barista, absen GPS + selfie.
- **Admin**: menu, stok & resep, kas, pengeluaran, purchase order, laporan, pelanggan, karyawan & absensi, staf & peran, konten situs, dan log aktivitas.

Semua modul MVP dan Fase 2 PRD sudah selesai dan teruji di lingkungan development. Status go-live ada di [docs/GO_LIVE_READINESS.md](docs/GO_LIVE_READINESS.md).

## Dokumen utama

| Untuk | Dokumen |
|---|---|
| Memasang di VPS dari nol sampai live, rilis, rollback, backup/restore | [docs/DEPLOY_VPS.md](docs/DEPLOY_VPS.md) |
| Perawatan rutin oleh Super Admin | [docs/PANDUAN_SUPERADMIN.md](docs/PANDUAN_SUPERADMIN.md) |
| Cara memakai aplikasi per peran | [docs/PANDUAN_PENGGUNA.md](docs/PANDUAN_PENGGUNA.md) |
| Aplikasi staf di HP (PWA/APK) | [docs/APLIKASI_HP.md](docs/APLIKASI_HP.md) |
| Uji penerimaan di outlet | [docs/SUDAYA_OUTLET_UAT.md](docs/SUDAYA_OUTLET_UAT.md) |
| Bukti test per milestone | [docs/QA_CHECKPOINT.md](docs/QA_CHECKPOINT.md) |

## Stack

PHP 8.5, Laravel 13, Inertia 3, React 19, TypeScript, Tailwind 4, Supabase (PostgreSQL, Auth, Storage, Realtime). Production: satu VPS Ubuntu (Nginx + PHP-FPM) + satu project Supabase, keduanya di Singapura ([deploy/](deploy/)).

## Menjalankan di lokal

```sh
composer install
npm install
cp .env.example .env        # isi DB_URL dan kunci Supabase development
php artisan key:generate
npm run build               # atau `npm run dev` saat mengubah tampilan
php artisan serve
```

Database development adalah project Supabase bersama: jangan menjalankan `migrate:fresh`, `migrate:rollback`, atau `db:wipe` ke sana. Project Supabase **baru dan kosong** dipasang dengan `php artisan migrate --force` (schema dasar di [database/schema/baseline](database/schema/baseline), lalu migration Laravel); di database yang sudah berisi, schema dasar dilewati.

## Perintah operasional

| Perintah | Fungsi |
|---|---|
| `php artisan sudaya:doctor` | Cek kesehatan sistem (konfigurasi, database, job otomatis, login, storage, cron, backup) |
| `php artisan sudaya:backup` | Backup database ke `BACKUP_DIR` (otomatis 02.30 WIB) |
| `php artisan sudaya:restore <file>` | Pulihkan backup ke project Supabase baru yang kosong |
| `php artisan sudaya:storage-setup` | Buat/selaraskan bucket Supabase Storage |
| `php artisan db:seed --force` | Data awal: company, brand, outlet, peran, izin (aman diulang) |
| `php artisan sudaya:admin-bootstrap --email=… --name="…"` | Super Admin pertama (sekali) |
| `php artisan sudaya:menu-asli` | Impor 22 menu asli (nonaktif, harga diisi admin) |
| `php artisan sudaya:demo-menu --remove` | Hapus menu contoh |

## Test

```sh
php artisan test                                       # unit dan feature, tanpa database
php artisan test -c phpunit.live.xml --testsuite Live  # Supabase development sungguhan
php artisan test -c phpunit.live.xml --testsuite E2E   # server PHP sungguhan, CSRF aktif
```

Test live membuat company dan akun berawalan `test-<runId>-` lalu menghapusnya. Bila run terputus: `php tests/Live/bin/fixture.php cleanup <runId>`. Jangan arahkan test ke database production, dan jangan jalankan dua test live bersamaan pada database yang sama.

## Keamanan

Repo ini **publik**. Jangan commit `.env`, file backup, kunci Supabase, atau data pribadi. Kunci production hanya ada di `/var/www/sudaya/shared/.env` di server.

## Dokumen latar belakang

[Rencana migrasi](docs/LARAVEL_MIGRATION_PLAN.md) · [Paritas fitur](docs/LARAVEL_FEATURE_PARITY.md) · [Pemetaan database](docs/LARAVEL_DATABASE_MAPPING.md) · [Kebutuhan hosting](docs/LARAVEL_HOSTING_REQUIREMENTS.md) · [Panduan deployment umum](docs/LARAVEL_DEPLOYMENT_GUIDE.md) · [Preflight staging](docs/STAGING_PREFLIGHT.md)
