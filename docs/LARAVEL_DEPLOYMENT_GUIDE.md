# Panduan deployment Laravel

Untuk staging dan production. **Belum ada deployment yang dilakukan.** Production butuh persetujuan owner dan gate go-live terpenuhi: paritas fungsi kritis, database production terpisah, backup dan restore teruji, serta UAT outlet lolos.

## 1. Sebelum deploy pertama

1. Pilih hosting sesuai [LARAVEL_HOSTING_REQUIREMENTS.md](LARAVEL_HOSTING_REQUIREMENTS.md) dan jalankan uji kelayakan di sana.
2. Buat project Supabase **baru** untuk production, satu region dengan hosting. Jangan memakai project dev yang dipakai test.
3. Terapkan schema ke project baru dari migration Drizzle yang ada (`npm run db:migrate` di repo Next.js, dengan `DATABASE_URL` production) lalu `php artisan db:seed`. Langkah ini hanya dijalankan dengan persetujuan owner.
4. Buat akun admin pertama di Supabase Dashboard, lalu `php artisan sudaya:admin-bootstrap --email=... --name="..."`.
5. Atur Supabase Auth:
   - Site URL = domain aplikasi.
   - Pengaturan rate limit sudah diperiksa (EXT-027).
   - Tidak ada sign-up publik.

## 2. Variabel lingkungan

Isi di panel hosting, bukan di Git. Contoh lengkap ada di `.env.example`.

| Variabel | Production |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Buat dengan `php artisan key:generate --show`, simpan di password manager. Mengganti kunci ini me-logout semua staf |
| `APP_URL` | `https://<domain>`. Dipakai untuk link undangan dan reset |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | URL pooler Supabase production, `sslmode=require` |
| `DB_EMULATE_PREPARES` | `true` bila memakai port 6543 |
| `SESSION_DRIVER` | `file` (satu server) |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_LIFETIME` | `10080` |
| `CACHE_STORE` | `file` |
| `AUTH_TOKEN_CACHE_DRIVER` | `file` |
| `QUEUE_CONNECTION` | `sync` |
| `INERTIA_SSR_ENABLED` | `false` |
| `INERTIA_ENCRYPT_HISTORY` | `true` |
| `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY` | Dari project production |
| `SUPABASE_SERVICE_ROLE_KEY` | Rahasia. Jangan pernah berawalan `VITE_` |
| `VITE_SUPABASE_URL`, `VITE_SUPABASE_PUBLISHABLE_KEY` | Diisi saat build aset |
| `ANDROID_APP_PACKAGE`, `ANDROID_APP_SHA256` | Opsional, hanya bila membuat APK aplikasi staf; lihat [APLIKASI_HP.md](APLIKASI_HP.md). Kosong = `/.well-known/assetlinks.json` 404 |
| `TRUSTED_IP_HEADER` | Header IP yang ditimpa proxy, mis. `X-Real-IP` di Nginx. Kosong bila tidak yakin |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stack` atau `daily`, `warning` |

## 3. Build dan rilis

Di CI atau mesin build, bukan di server:

```sh
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

Unggah seluruh proyek kecuali `node_modules`, `.env`, `tests`, dan `storage/*` yang berisi data. Ke direktori rilis baru, lalu di server:

```sh
php artisan optimize          # cache config, route, event, view
php artisan storage:link      # bila nanti dipakai
```

Document root web server menunjuk ke `public/`, tidak pernah ke root proyek. Konfigurasi Nginx dasar ada di dokumentasi deployment Laravel. Header keamanan sudah dikirim aplikasi, jadi jangan menambah `X-Frame-Options` yang berbeda di Nginx.

Tidak ada `php artisan migrate` di rilis M1. Mulai M2, migration hanya dijalankan setelah `--pretend` dibaca dan disetujui, sesuai [LARAVEL_DATABASE_MAPPING.md](LARAVEL_DATABASE_MAPPING.md).

## 4. Rilis tanpa mengganggu outlet

1. Rilis pada jam sepi.
2. Siapkan direktori rilis baru, `optimize`, lalu pindahkan symlink `current` ke rilis baru.
3. `storage/` dibagi antar rilis lewat symlink, agar sesi dan token staf tidak hilang.
4. Cek `GET /up` (aplikasi hidup) dan `GET /api/health` (database terjangkau).

**Rollback:** kembalikan symlink ke rilis sebelumnya. Rilis yang mengandung migration hanya bisa di-rollback kodenya; migration additive tidak perlu dibalik.

## 5. Setelah deploy

- Login sebagai Super Admin, buka `/admin/audit`, pastikan `auth.login` tercatat.
- Cek header di browser: CSP, HSTS, cookie `Secure` dan `HttpOnly`.
- Pantau `storage/logs` dan `/api/health` dengan uptime monitor.
- Aplikasi staf (PWA): buka `https://<domain>/login` di Chrome Android dan pastikan kartu **Pasang aplikasi** muncul. Sajikan `public/sw.js` dengan `Cache-Control: no-cache` dan `.webmanifest` dengan MIME `application/manifest+json`. Panduan staf dan APK: [APLIKASI_HP.md](APLIKASI_HP.md).
- `php artisan cache:clear` aman dijalankan karena tidak menyentuh store token. Jangan menghapus `storage/framework/auth-tokens` kecuali ingin me-logout semua staf.

## 6. Hal yang belum siap

- Uji transaction pooler dan uji beban (M8).
- Backup dan uji restore Supabase production (EXT-028).
- Monitoring error terpusat (M8).
- Domain dan HTTPS (EXT-021).
