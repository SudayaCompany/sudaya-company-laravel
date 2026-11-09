# Staging preflight — Laravel + Supabase Singapura

Disusun 5 November 2026 dari audit read-only atas repo Laravel (`fb01dfd`) dan schema Drizzle di repo Next.js. **Belum ada resource cloud, migration, atau deployment.** Dokumen ini tidak menyatakan aplikasi siap production.

Dokumen terkait, yang tetap berlaku kecuali disebut lain di §9: [STAGING_HOSTING_PLAN.md](STAGING_HOSTING_PLAN.md), [SUPABASE_STAGING_PLAN.md](SUPABASE_STAGING_PLAN.md), [LARAVEL_STAGING_RUNBOOK.md](LARAVEL_STAGING_RUNBOOK.md), [LARAVEL_DEPLOYMENT_GUIDE.md](LARAVEL_DEPLOYMENT_GUIDE.md), [LARAVEL_HOSTING_REQUIREMENTS.md](LARAVEL_HOSTING_REQUIREMENTS.md).

## Ringkasan

**Kandidat staging: commit `fb01dfd`.** Lolos regresi penuh di lingkungan development: Live suite M1–M4 113/113 (691 assertion, exit 0), unit 142/142, E2E 35/35, dan browser QA 59/59, 73/73, 22/22, 137/137. Residual QA M4 R1–R4 **CLOSED**. Rinciannya di [QA_CHECKPOINT.md](QA_CHECKPOINT.md).

Kode siap dipasang di staging tanpa perubahan bila hosting memutus HTTPS di web server yang sama (Nginx + PHP-FPM). Yang menghalangi deploy sekarang adalah keputusan dan akun milik owner, bukan kode: hosting, remote Git, project Supabase staging, dan domain.

Tiga temuan audit perlu ditangani sebelum atau saat deploy:

1. **Proxy tepercaya belum dikonfigurasi.** Bila HTTPS diputus di load balancer atau CDN di depan PHP, Laravel menganggap request `http`. Akibatnya URL aset Vite dan redirect bisa tercetak `http://`, dan halaman diblokir sebagai mixed content. Ini tidak terjadi bila Nginx + PHP-FPM memutus HTTPS sendiri (VPS). Untuk hosting berbasis proxy perlu perubahan kecil (`trustProxies`) dengan persetujuan owner.
2. **Penjaga "test live tidak boleh di production" tidak selalu bekerja.** `phpunit.live.xml` mengisi `APP_ENV=testing`. Bila `APP_ENV=production` hanya ada di file `.env`, nilai dari PHPUnit yang menang, sehingga penjaga `isProduction()` di `LiveTestCase` tidak terpicu. Pengaman yang efektif: rilis tanpa folder `tests/` dan `composer install --no-dev` (PHPUnit tidak terpasang). Lihat §4.
3. **Default sesi dan cache Laravel adalah `database`, tetapi tabel `sessions` dan `cache` tidak ada di schema.** Staging wajib mengisi `SESSION_DRIVER=file` dan `CACHE_STORE=file`. Koneksi default juga `sqlite`, jadi `DB_CONNECTION=pgsql` wajib.

## 1. Checklist kesiapan

Kategori: **Tersedia** (sudah ada di kode, cukup dipakai), **Konfigurasi** (diisi saat deploy, tanpa ubah kode), **Implementasi** (butuh perubahan kode), **Owner** (butuh keputusan atau akun owner).

| Area | Kategori | Temuan dan bukti |
|---|---|---|
| PHP dan ekstensi | Konfigurasi | `composer.json`: `php ^8.3`. Dev memakai 8.5.12. Wajib `pdo_pgsql`, `intl`, `sodium`, `curl` + CA bundle terbaru, plus ekstensi standar Laravel. Detail di LARAVEL_HOSTING_REQUIREMENTS §Kebutuhan minimum |
| Dependency Composer | Tersedia | Produksi hanya `laravel/framework ^13.17`, `inertiajs/inertia-laravel ^3.6`, `firebase/php-jwt ^7.2`, `laravel/tinker`. `composer.lock` ada di repo. Pasang dengan `--no-dev --optimize-autoloader` |
| Runtime Laravel 13 | Konfigurasi | `php artisan optimize` di setiap rilis. `storage/` dan `bootstrap/cache` harus bisa ditulis, dan `storage/` dibagi antar rilis |
| Aset Inertia/React | Tersedia | Build di mesin build (`npm ci && npm run build`, Node 22), unggah `public/build`. SSR tidak dipakai (`INERTIA_SSR_ENABLED=false`), jadi server tidak butuh Node. Font self-hosted (CSP `font-src 'self'`). Bundle tidak memakai `import.meta.env`; variabel `VITE_SUPABASE_*` tidak diperlukan |
| Koneksi PostgreSQL Supabase | Konfigurasi | `DB_CONNECTION=pgsql` wajib (default `sqlite`). `DB_URL` session pooler Singapura dengan `sslmode=require` (default config `prefer`). `search_path` tetap `public`. Transaction pooler (6543) butuh `DB_EMULATE_PREPARES=true` dan **belum diuji**; pakai session pooler (5432) untuk staging |
| Auth, JWKS, sesi | Konfigurasi + Owner | JWT diverifikasi lokal hanya lewat JWKS (`SupabaseTokenVerifier`), cache 10 menit. Project staging **wajib memakai JWT signing key asimetris** (ES256/RS256); dengan HS256 lama login gagal. Sesi Laravel hanya memegang id; token Supabase ada di store file terpisah (`storage/framework/auth-tokens`). Aplikasi membaca `auth.users` dan `auth.sessions`, jadi user DB harus punya SELECT di schema `auth` (benar untuk user `postgres` pooler) |
| Penyimpanan file | Konfigurasi | Foto menu di Supabase Storage (bucket `menu-images`), bukan disk server. Disk lokal hanya untuk sesi, cache, token, dan log. Bucket dibuat lewat `npm run storage:setup` di repo Next.js (§2 langkah 6) |
| Scheduler/cron | **Diperlukan sejak M7-lite** | Kedaluwarsa order dan pembersihan rate limit berjalan di **pg_cron** database. Retensi foto absen 90 hari (`sudaya:purge-attendance-photos`, harian 03.15 WIB) berjalan lewat scheduler Laravel, jadi server butuh satu baris cron: `* * * * * cd /var/www/sudaya && php artisan schedule:run >> /dev/null 2>&1`. Cek: `php artisan schedule:list` |
| Logging | Konfigurasi | `LOG_CHANNEL=daily`, `LOG_LEVEL=warning`. `QueryException` dicatat tanpa nilai parameter; health check hanya mencatat kelas error. Log di `storage/logs` (direktori bersama). Monitoring terpusat belum ada (M8) |
| HTTPS dan cookie Secure | Konfigurasi + Implementasi (bergantung hosting) | `SESSION_SECURE_COOKIE=true`. Cookie token pesanan guest dipaksa `Secure` bila `APP_ENV=production`. Belum ada `trustProxies`: wajib bila HTTPS diputus di proxy (temuan 1) |
| CSP dan HSTS | Tersedia | `SecurityHeaders`: CSP dengan nonce, `connect-src` ke `SUPABASE_URL` dan WebSocket Realtime, `X-Frame-Options DENY`, `Permissions-Policy`. **HSTS hanya bila `APP_ENV=production`**, jadi staging memakai `production` |
| Multi-worker | Konfigurasi | Terbukti di QA lokal 6 worker (browser M4 73/73). Satu server dengan PHP-FPM beberapa worker aman memakai sesi dan token berbasis file. Lebih dari satu server butuh store bersama (Redis atau tabel) = Implementasi. Batasi `pm.max_children` di bawah batas klien session pooler paket Supabase staging |
| Keamanan berbasis environment | Konfigurasi | `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `INERTIA_ENCRYPT_HISTORY=true`. `php.ini`: `display_errors=Off`, `zend.exception_ignore_args=On`, `expose_php=Off` |
| Health check | Tersedia | `GET /up` (aplikasi hidup, tanpa DB) dan `GET /api/health` (`select 1`, 503 bila DB gagal, tanpa detail) |
| Rollback | Tersedia (prosedur) | Rilis berbasis direktori + symlink `current`; migration hanya aditif, jadi rollback = kode. Belum pernah dilatih |
| Realtime | Konfigurasi | Sinyal tanpa data lewat `realtime.send(..., false)` di transaksi; browser berlangganan channel **publik** dengan publishable key. Bila project hanya mengizinkan channel privat, aplikasi jatuh ke polling (tetap jalan, lebih lambat) |
| Email undangan/reset | Owner | Dikirim Supabase Auth. Pengirim bawaan Supabase dibatasi kuota rendah; untuk UAT banyak staf pertimbangkan SMTP sendiri |
| Remote Git | Owner | Repo Laravel hanya lokal (kandidat kode `fb01dfd`) |
| Hosting, domain, Supabase staging | Owner | Belum ada (EXT-021, EXT-028) |

## 2. Migration: urutan untuk database Supabase kosong

Sumber schema: 10 migration Drizzle (`drizzle/0000_init.sql` … `0009_cash_movements.sql`, repo Next.js) lalu 1 migration Laravel. **Jangan dijalankan sebelum disetujui owner.**

| Langkah | Objek | Sumber | Verifikasi |
|---|---|---|---|
| 0 | Prasyarat project | Supabase Dashboard | Region `ap-southeast-1`; PostgreSQL ≥ 15 (`UNIQUE NULLS NOT DISTINCT`); `select to_regclass('public.companies')` → `null`; `select to_regprocedure('realtime.send(jsonb,text,text,boolean)')` tidak `null`; JWT signing key asimetris aktif |
| 1 | Extension `pg_cron` | Dashboard → Database → Extensions (disarankan diaktifkan **sebelum** migration) | `select extname from pg_extension where extname = 'pg_cron'` → 1 baris. Migration 0004 memakai `create extension if not exists`, jadi tetap aman |
| 2 | Migration Drizzle 0000–0009: 18 enum, 41 tabel `public` (semua RLS, 0 policy), foreign key termasuk ke `auth.users`, index termasuk partial unique (`cash_sessions_one_open_per_outlet`, `orders_pending_expiry_idx`, dll.), 3 fungsi (`expire_pending_orders`, `inventory_ledger_immutable`, `audit_logs_immutable`, semuanya `search_path=''`, bukan SECURITY DEFINER), 4 trigger, REVOKE dari PUBLIC/anon/authenticated, 2 job pg_cron | `npm run db:migrate` (drizzle-kit) di repo Next.js lewat **session pooler** staging | `drizzle.__drizzle_migrations` 10 baris; checklist §Verifikasi di SUPABASE_STAGING_PLAN |
| 3 | Bucket Storage `menu-images` (publik, 2 MB), `inventory-evidence` dan `attendance-evidence` (privat, 3 MB). Tidak ada policy `storage.objects` | `npm run storage:setup` di repo Next.js (Node 22, Storage API dengan service role) | Dashboard → Storage: 3 bucket; `select count(*) from pg_policies where schemaname = 'storage' and tablename = 'objects'` sesuai bawaan project (tanpa policy anon/authenticated tambahan) |
| 4 | Migration Laravel `2026_11_04_000001`: tabel `outlet_tables` (RLS), RLS di tabel `migrations`; `2026_11_09_000001`: nilai `gps_selfie` pada enum `attendance_method` (aditif, di luar transaksi; disetujui owner 9 November) | `php artisan migrate --force` dari server/rilis staging, setelah file dibaca. **Jangan `--pretend`** (membuat tabel `migrations` tanpa RLS) | `select count(*) from pg_tables where schemaname = 'public' and not rowsecurity` → `0`; total 43 tabel `public` |
| 5 | Seed minimum: company `sudaya`, brand `sudaya-coffee`, outlet `SDY01` (nama sementara, alamat kosong), 41 permission, 6 role, mapping role–permission | `php artisan db:seed --force` (repo Laravel). Jangan juga menjalankan seeder Next.js | 1 company, 1 brand, 1 outlet, 41 permission, 6 role; idempotent bila diulang |
| 6 | Super Admin pertama | Buat user di Dashboard → Authentication, lalu `php artisan sudaya:admin-bootstrap --email=… --name="…"` | Login berhasil; `/admin/audit` mencatat `auth.login`; bootstrap kedua ditolak |
| 7 | Data outlet asli | `/admin/outlets` (nama, alamat, prefix antrean, batas bayar, daftar meja) | QR meja terdaftar membuka menu; meja tak terdaftar menampilkan "QR meja tidak dikenali" |
| 8 | Cek job pg_cron berhasil, bukan hanya aktif | SQL editor | `select jobname, status, count(*) from cron.job_run_details where start_time > now() - interval '15 minutes' group by 1, 2` → hanya `succeeded` |

### Risiko pada project kosong

| No | Risiko | Mitigasi |
|---|---|---|
| M1 | **Skrip Drizzle membaca `.env.local` development** (`drizzle.config.ts`, `scripts/env.ts`) dan hanya melewatkan variabel yang sudah di-export. Bila sebagian variabel tidak di-export, perintah bisa mengenai project dev | Jalankan dari checkout bersih **tanpa** `.env.local`, export `DATABASE_URL`, `NEXT_PUBLIC_SUPABASE_URL`, dan `SUPABASE_SERVICE_ROLE_KEY` staging di shell, lalu cocokkan project ref dan region sebelum menekan Enter (checklist no. 4 SUPABASE_STAGING_PLAN) |
| M2 | Semua migration Drizzle berjalan dalam **satu transaksi**: satu kegagalan membatalkan kesepuluhnya | Bukan kerusakan; perbaiki penyebab lalu ulangi. Cek prasyarat langkah 0 lebih dulu |
| M3 | `realtime.send` tidak dicek saat fungsi dibuat. Bila tidak ada, job kedaluwarsa gagal setiap menit dan transaksi order Laravel gagal | Cek `to_regprocedure` di langkah 0 dan `cron.job_run_details` di langkah 8 |
| M4 | pg_cron dibuat di dalam transaksi migration; job berjalan sebagai role yang menjalankan migration | Aktifkan pg_cron dari Dashboard dulu; jalankan migration sebagai user `postgres` pooler |
| M5 | Verifier JWT hanya JWKS | Pastikan signing key asimetris sebelum bootstrap admin |
| M6 | `service_role` tetap punya EXECUTE pada fungsi (REVOKE hanya PUBLIC/anon/authenticated) | Dapat diterima selama service role key hanya di server |
| M7 | Realtime memakai channel publik | Jangan aktifkan "private channels only" di staging; bila aktif, aplikasi jatuh ke polling |
| M8 | Migration Laravel bergantung pada tabel Drizzle (FK ke `companies`/`outlets`) | Urutan wajib: Drizzle → Storage → Laravel migrate → seed → bootstrap |

## 3. Matrix environment

Hanya nama variabel, tujuan, dan sumber. Nilai tidak pernah ditulis di Git atau dokumen. Sumber "password manager" berarti entri terpisah per environment.

| Variabel | Tujuan | Development (laptop) | Staging Singapura | Production Singapura |
|---|---|---|---|---|
| `APP_NAME` | Nama aplikasi, prefix cookie | `.env` lokal | Panel hosting | Panel hosting |
| `APP_ENV` | Perilaku keamanan (HSTS, cookie Secure, penjaga fixture) | `local` | `production` | `production` |
| `APP_KEY` | Enkripsi sesi, cookie, store token | `.env` lokal | Baru, dibuat `key:generate --show`, password manager | Baru, berbeda dari staging |
| `APP_DEBUG` | Tampilan error | `true`/`false` | `false` | `false` |
| `APP_URL` | Link undangan/reset, URL konsol | `http://127.0.0.1:…` | `https://staging.<domain>` | `https://<domain>` |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_MAINTENANCE_DRIVER` | Default Laravel | `.env.example` | Sama | Sama |
| `DB_CONNECTION` | Driver DB (default `sqlite`) | `pgsql` | `pgsql` | `pgsql` |
| `DB_URL` | Session pooler Supabase, `sslmode=require` | Project dev eu-west-1 | Project staging `ap-southeast-1`, password manager | Project production `ap-southeast-1`, password manager |
| `DB_SSLMODE` | Fallback bila `DB_URL` tanpa `sslmode` | — | `require` | `require` |
| `DB_EMULATE_PREPARES` | Wajib `true` untuk transaction pooler | `false` | `false` (session pooler) | Diputuskan setelah uji M8 |
| `SESSION_DRIVER` | Penyimpanan sesi (default `database`, tabel tidak ada) | `file` | `file` | `file` (satu server) |
| `SESSION_LIFETIME` | Menit idle | `.env` lokal | `10080` | `10080` |
| `SESSION_ENCRYPT` | Enkripsi isi sesi | bebas | `true` | `true` |
| `SESSION_SECURE_COOKIE` | Cookie hanya HTTPS | `false` | `true` | `true` |
| `SESSION_PATH`, `SESSION_DOMAIN` | Scope cookie | default | default (kosong) | default (kosong) |
| `CACHE_STORE` | Cache aplikasi (default `database`, tabel tidak ada) | `file` | `file` | `file` |
| `AUTH_TOKEN_CACHE_DRIVER` | Store token Supabase, terpisah dari cache | `file` | `file` | `file` |
| `QUEUE_CONNECTION` | Antrian job | `sync` | `sync` | `sync` |
| `BROADCAST_CONNECTION` | Tidak dipakai (Realtime lewat DB) | `log` | `log` | `log` |
| `FILESYSTEM_DISK` | Disk lokal (foto ke Supabase Storage) | `local` | `local` | `local` |
| `INERTIA_SSR_ENABLED` | SSR tidak dipakai | `false` | `false` | `false` |
| `INERTIA_ENCRYPT_HISTORY` | Riwayat browser terenkripsi (butuh HTTPS) | `false`/`true` | `true` | `true` |
| `MAIL_MAILER`, `MAIL_FROM_*` | Laravel tidak mengirim email (undangan lewat Supabase Auth) | `log` | `log` | `log` |
| `SUPABASE_URL` | Auth, Storage, Realtime, CSP | Project dev | Project staging | Project production |
| `SUPABASE_PUBLISHABLE_KEY` | Kunci publik untuk Realtime browser dan Auth | Project dev | Password manager staging | Password manager production |
| `SUPABASE_SERVICE_ROLE_KEY` | **Rahasia**, admin Auth dan upload Storage, hanya server | Project dev | Password manager staging | Password manager production |
| `VITE_APP_NAME`, `VITE_SUPABASE_URL`, `VITE_SUPABASE_PUBLISHABLE_KEY` | Tidak dibaca bundle saat ini | opsional | Kosongkan | Kosongkan |
| `TRUSTED_IP_HEADER` | Header IP client dari proxy tepercaya untuk rate limit | kosong | Sesuai hosting (mis. `X-Real-IP` di Nginx), kosong bila ragu | Sama dengan staging |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL` | Logging | `stack`, `debug` | `daily`, `warning`, `null` | `daily`, `warning`, `null` |

Untuk menjalankan migration Drizzle dan `storage:setup` (repo Next.js, di mesin operator) dibutuhkan `DATABASE_URL`, `NEXT_PUBLIC_SUPABASE_URL`, dan `SUPABASE_SERVICE_ROLE_KEY` staging, di-export di shell, bukan di `.env.local` (risiko M1).

## 4. Pengaman agar test fixture tidak berjalan di staging/production

| Lapisan | Status | Catatan |
|---|---|---|
| `tests/Live/bin/fixture.php` dan `order-op.php` menolak bila `APP_ENV=production` | Efektif bila `.env` staging berisi `production` | Diperiksa sebelum fixture dibuat |
| `LiveTestCase` menolak `APP_ENV=production` | **Tidak selalu efektif** | `phpunit.live.xml` mengisi `APP_ENV=testing`. Bila `APP_ENV` hanya ada di `.env`, PHPUnit yang menang, sehingga penjaga tidak terpicu. Hanya efektif bila hosting menyuntikkan `APP_ENV` sebagai variabel proses |
| Rilis tanpa folder `tests/` | Wajib (prosedur) | Sudah tertulis di LARAVEL_DEPLOYMENT_GUIDE §3. Jadikan butir checklist rilis |
| `composer install --no-dev` | Wajib (prosedur) | PHPUnit dan autoload `Tests\` tidak terpasang, sehingga `php artisan test` dan skrip fixture tidak bisa berjalan |
| Kredensial staging tidak masuk `.env` laptop | Wajib (prosedur) | Test otomatis membaca `DB_URL` dari `.env` laptop. Bila staging dimasukkan ke sana, test akan menulis data `test-*` ke staging |
| Penjaga di kode berdasarkan host DB atau flag eksplisit (mis. hanya izinkan test live bila project ref = dev) | Implementasi (opsional, butuh persetujuan) | Menutup celah lapisan kedua secara permanen |

## 5. Rencana deployment staging

| No | Langkah | Pelaksana | Cara memverifikasi berhasil |
|---|---|---|---|
| 1 | Owner menyetujui hosting (penyedia, paket, region Singapura) | Owner | Penawaran tertulis; uji kelayakan lolos: `php -v` ≥ 8.3, `php -m` memuat `pdo_pgsql`/`intl`/`sodium`, koneksi ke pooler Supabase dan HTTPS ke `*.supabase.co` dari server, SSH + Composer, PHP-FPM multi-worker. Jawab juga: apakah HTTPS diputus di proxy? (menentukan perlu `trustProxies`) |
| 2 | Owner membuat remote Git privat, lalu push commit terbaru yang disetujui (kode kandidat `fb01dfd`; commit dokumen sesudahnya tidak mengubah kode) | Owner | Remote privat; `git ls-remote` menunjukkan hash yang sama dengan lokal; deploy key read-only untuk server |
| 3 | Owner membuat project Supabase staging `ap-southeast-1` | Owner | Checklist no. 1–6 SUPABASE_STAGING_PLAN; JWT signing key asimetris; `realtime.send` ada; pg_cron diaktifkan |
| 4 | Setup kredensial | Owner + pengelola | Entri password manager staging lengkap (DB URL, URL, publishable, service role, APP_KEY baru); tidak ada kredensial dev di panel hosting; tidak ada kredensial staging di `.env` laptop |
| 5 | Setup runtime Laravel | Pengelola | Struktur `releases/`, `shared/storage`, `shared/.env` atau env panel; document root `current/public`; `php.ini` production; `php artisan optimize` tanpa error; `GET /up` → 200 |
| 6 | Migration terkontrol (§2 langkah 0–6) | Pengelola, disetujui owner | `/api/health` → 200; 43 tabel `public`, 0 tanpa RLS, 0 policy; 2 job pg_cron sukses; 3 bucket; seed dan Super Admin berhasil |
| 7 | Scheduler | Pengelola | Tidak ada cron server. Verifikasi `cron.job_run_details` sukses, dan order QR yang tidak dibayar berubah "Dibatalkan" setelah TTL outlet |
| 8 | Build dan publish aset | Mesin build | `npm ci && npm run build` dari commit yang sama; `public/build/manifest.json` ada di rilis; halaman tanpa error console/CSP |
| 9 | HTTPS | Owner (DNS) + pengelola | Sertifikat valid; `http://` dialihkan ke `https://`; header HSTS ada; cookie sesi `Secure` + `HttpOnly`; aset dimuat dari `https://` (cek temuan 1) |
| 10 | Smoke test | Pengelola | 8 cek di LARAVEL_STAGING_RUNBOOK §10 lolos, ditambah: halaman depan `/`, menu `/order?outlet=<kode>`, QR meja terdaftar vs tidak terdaftar, kiosk |
| 11 | Input master data | Owner/Manager | Sesuai MASTER_DATA_PREPARATION: menu dan harga asli, foto, outlet dan meja, akun staf per peran. Tanpa harga atau karyawan fiktif |
| 12 | UAT outlet | Manager/Owner | 24 skenario SUDAYA_OUTLET_UAT di perangkat outlet, defect tercatat, tanda tangan penerimaan |
| 13 | Rollback plan | Pengelola | Dilatih sekali: pindahkan symlink ke rilis sebelumnya + `php artisan optimize`, `/up` dan `/api/health` tetap 200, staf tidak ter-logout (`storage/` bersama). Restore backup diuji ke project terpisah |

## 6. Risiko teknis

| No | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| T1 | HTTPS diputus di proxy tanpa `trustProxies` | Aset dan redirect `http://`, halaman rusak (mixed content) | Pilih hosting yang memutus HTTPS di Nginx, atau tambahkan `trustProxies` (perubahan kecil, butuh persetujuan) dan uji di staging |
| T2 | Jumlah worker PHP melebihi batas klien session pooler | Error koneksi saat ramai | Set `pm.max_children` di bawah batas paket; transaction pooler hanya setelah uji M8 |
| T3 | Penjaga test live tidak efektif (§4) | Data test tertulis ke staging | Rilis tanpa `tests/` dan tanpa dev dependency; kredensial staging tidak di laptop test |
| T4 | Migration mengenai project yang salah (M1) | Data dev/production tercampur | Checklist identitas project, shell bersih tanpa `.env.local` |
| T5 | JWT signing key project masih HS256 | Semua login gagal | Cek sebelum bootstrap admin |
| T6 | Kuota email bawaan Supabase | Undangan staf UAT tertunda | SMTP sendiri (keputusan owner) |
| T7 | Jaringan outlet memblokir WebSocket Supabase | KDS dan status pelanggan jatuh ke polling 5–15 detik | Uji di Wi-Fi outlet saat UAT |
| T8 | Server tunggal | Tidak ada redundansi | Diterima untuk soft launch; multi-server butuh store sesi bersama |
| T9 | Belum ada monitoring terpusat | Error production terlambat diketahui | Uptime monitor ke `/api/health` minimal; monitoring penuh di M8 |
| T10 | Latensi production belum diukur | Kecepatan aktual belum diketahui | Ukur dari server staging ke Supabase staging (runbook §6); angka laptop tidak dipakai |

## 7. Blocker staging yang masih terbuka

1. Hosting belum dipilih dan dibayar (owner).
2. Remote Git belum ada (owner).
3. Project Supabase staging Singapura belum dibuat (owner).
4. Domain/subdomain dan DNS staging (EXT-021, owner).
5. Bergantung pada hosting: `trustProxies` bila HTTPS diputus di proxy (T1).

Bukan blocker lagi: Live suite penuh kandidat `fb01dfd` lolos 113/113 (exit 0, 5 November 13.11–14.04), dan residual QA M4 R1–R4 sudah CLOSED.

## 8. Rekomendasi hosting (teknis)

1. **VPS atau managed server di Singapura dengan Nginx + PHP-FPM yang memutus HTTPS sendiri** paling cocok dengan kode saat ini: tanpa perubahan kode, sesi/token berbasis file aman di satu server, dan `TRUSTED_IP_HEADER=X-Real-IP` sederhana diatur. Alat seperti Forge mengurangi beban pengelolaan.
2. **Managed Laravel berbasis proxy/load balancer** (mis. platform serverless atau multi-instance) tetap layak, tetapi butuh konfigurasi `trustProxies` dan, bila lebih dari satu instance, store sesi bersama (Redis atau tabel `sessions`). Keduanya perubahan kode yang harus disetujui dan diuji dulu.
3. **Shared hosting** hanya bila lolos uji kelayakan di langkah 5.1. Risiko utamanya port Postgres keluar diblokir dan tidak ada PHP-FPM multi-worker.
4. Staging dan production di penyedia dan region yang sama dengan Supabase (`ap-southeast-1`). Harga tidak dicek dalam audit ini.

## 9. Ketidaksesuaian dokumen lama (untuk dirapikan setelah disetujui)

| Dokumen | Butir |
|---|---|
| SUPABASE_STAGING_PLAN §Verifikasi | Mengharapkan `pgcrypto` dan `uuid-ossp`; migration hanya membuat `pg_cron` (`gen_random_uuid` bawaan Postgres) |
| SUPABASE_STAGING_PLAN §Environment, catatan | Menyebut `LiveTestCase` berhenti di `APP_ENV=production`; tidak selalu benar (§4) |
| SUPABASE_STAGING_PLAN checklist no. 10, runbook §8 | Hanya memeriksa job aktif, bukan sukses (`cron.job_run_details`) |
| SUPABASE_STAGING_PLAN §Schema | Belum memperingatkan fallback `.env.local` skrip Drizzle, belum menyebut mengaktifkan pg_cron lebih dulu dan cek `realtime.send` |
| LARAVEL_DEPLOYMENT_GUIDE §3 | Masih menyebut review migration dengan `--pretend`; ganti dengan membaca file (jebakan tabel `migrations` tanpa RLS) |
| LARAVEL_DATABASE_MAPPING | Masih menyebut "belum ada migration Laravel" dan 41 tabel; kini ada `outlet_tables` (43 tabel) |
| LARAVEL_STAGING_RUNBOOK §7 | Belum menyebut perintah Drizzle, pooler, langkah Storage, dan urutan seed sebelum bootstrap |

## 10. Langkah minimum untuk deploy staging

1. Owner: pilih hosting Singapura, buat remote Git privat, buat project Supabase staging Singapura, siapkan subdomain.
2. Pengelola: uji kelayakan server (§5 no. 1) dan jawab pertanyaan HTTPS di proxy.
3. Bila HTTPS diputus di proxy: ajukan perubahan `trustProxies` untuk disetujui, uji, commit.
4. Siapkan kredensial staging di password manager dan panel hosting (§3).
5. Jalankan migration terkontrol §2 langkah 0–6 setelah owner menyetujui.
6. Deploy rilis dari commit yang disetujui (tanpa `tests/`, `--no-dev`), `optimize`, HTTPS.
7. Smoke test (§5 no. 10). Gagal → perbaiki atau rollback, jangan lanjut ke UAT.

## 11. Yang membutuhkan persetujuan owner

| Butir | Keterangan |
|---|---|
| Hosting dan biaya | Penyedia, paket, region, penanggung jawab server |
| Remote Git dan push | Repo privat, siapa yang punya akses, deploy key |
| Project Supabase staging | Paket (batas koneksi pooler, backup), region `ap-southeast-1` |
| Domain dan DNS | Subdomain staging |
| Akses secret | Siapa memegang service role key dan APP_KEY |
| Migration, seed, bootstrap admin di staging | Setiap eksekusi |
| SMTP sendiri untuk Supabase Auth | Bila kuota bawaan tidak cukup untuk UAT |
| Perubahan kode bergantung hosting | `trustProxies`; store sesi bersama bila multi-instance; penjaga test live berbasis host DB |
| Deploy staging dan jadwal UAT | Setelah smoke test lolos |
| Production | Terpisah, setelah UAT ditandatangani |
