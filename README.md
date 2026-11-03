# Sudaya Coffee

Sistem operasional Sudaya Company: POS, order pelanggan, antrean barista, stok, kas, absensi, dan admin. Aplikasi production ditulis dengan Laravel. Repo Next.js lama (`C:\magang\SudayaCoffee`) hanya menjadi referensi sampai semua modul pindah.

Status per modul ada di [docs/LARAVEL_FEATURE_PARITY.md](docs/LARAVEL_FEATURE_PARITY.md). Saat ini baru M1 (login, sesi, role dan permission, pengguna, audit) yang sudah berjalan di Laravel.

## Stack

PHP 8.5, Laravel 13, Inertia 3, React 19, TypeScript, Tailwind 4, Supabase (Postgres, Auth, Storage, Realtime).

## Menjalankan di lokal

```sh
composer install
npm install
cp .env.example .env        # isi DB_URL dan kunci Supabase dev
php artisan key:generate
npm run build               # atau `npm run dev` saat mengubah tampilan
php artisan serve
```

Schema database dikelola di Supabase. Jangan menjalankan `php artisan migrate`, `migrate:fresh`, atau `db:wipe` ke database bersama; lihat [docs/LARAVEL_DATABASE_MAPPING.md](docs/LARAVEL_DATABASE_MAPPING.md).

Data awal dan admin pertama:

```sh
php artisan db:seed                                   # aman diulang
php artisan sudaya:admin-bootstrap --email=... --name="..."
```

## Test

```sh
php artisan test                                       # unit dan feature, tanpa database
php artisan test -c phpunit.live.xml --testsuite Live  # Supabase dev sungguhan
php artisan test -c phpunit.live.xml --testsuite E2E   # server PHP sungguhan, CSRF aktif
```

Test live membuat company dan akun dengan prefix `test-<runId>-` lalu menghapusnya. Bila run terputus, bersihkan dengan `php tests/Live/bin/fixture.php cleanup <runId>`. Jangan arahkan test live ke database production.

## Dokumen

- [Rencana migrasi](docs/LARAVEL_MIGRATION_PLAN.md)
- [Paritas fitur](docs/LARAVEL_FEATURE_PARITY.md)
- [Pemetaan database](docs/LARAVEL_DATABASE_MAPPING.md)
- [Kebutuhan hosting](docs/LARAVEL_HOSTING_REQUIREMENTS.md)
- [Panduan deployment](docs/LARAVEL_DEPLOYMENT_GUIDE.md)
