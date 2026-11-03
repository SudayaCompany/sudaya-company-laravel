# Rencana migrasi ke Laravel

Diperbarui 3 November 2026. Dokumen ini menggantikan `docs/LARAVEL_MIGRATION_PLAN.md` di repo Next.js, yang kini hanya arsip keputusan 2 November.

## Keputusan owner

1. Laravel adalah satu-satunya backend production. Next.js tidak dipakai lagi sebagai aplikasi production.
2. Next.js di `C:\magang\SudayaCoffee` hanya referensi fitur, aturan bisnis, schema, UI, dan test. Tidak ada fitur baru di Next.js.
3. Supabase tetap dipakai: Postgres, Auth, Storage, Realtime.
4. Hosting PHP, bukan Vercel. Pilihan ada di [LARAVEL_HOSTING_REQUIREMENTS.md](LARAVEL_HOSTING_REQUIREMENTS.md).
5. Rencana production (3 November): Supabase production di Singapura (`ap-southeast-1`), hosting di Singapura dengan managed Laravel sebagai kandidat utama dan VPS sebagai alternatif. Pembelian hosting, pembuatan database production, dan deploy belum disetujui.

## Stack

| Lapisan | Pilihan | Catatan |
|---|---|---|
| Runtime | PHP 8.5 (minimal 8.3 untuk Laravel 13) | Lokal: 8.5.12 portabel |
| Framework | Laravel 13.39 | |
| Tampilan | Inertia 3, React 19, TypeScript, Tailwind 4, komponen shadcn yang sama dengan Next.js | Tanpa SSR, tanpa proses Node di production |
| Database | Supabase Postgres, schema yang sama | Query Builder untuk operasi transaksi, Eloquent untuk model dan relasi |
| Auth | Supabase Auth lewat REST, JWT diverifikasi lokal dengan JWKS (ES256) | Lihat bagian M1 |
| Otorisasi | Gate per permission, Policy untuk aturan anti eskalasi, middleware per area | Katalog RBAC sama persis dengan Next.js |
| Realtime | Supabase Realtime dari browser, `realtime.send` di transaksi DB | Mulai M4, dengan polling sebagai cadangan |
| Pekerjaan terjadwal | `pg_cron` di database (sudah berjalan) | Scheduler Laravel hanya bila perlu |
| Queue | Tidak dipakai (`QUEUE_CONNECTION=sync`) | Ditambah hanya jika ada kebutuhan nyata |

## Roadmap

Owner menetapkan urutan M1–M9 di bawah. Roadmap lama (2 November) membagi modul berbeda; pemetaannya ada di kolom terakhir. Cakupan PRD tidak berubah, hanya pengelompokannya.

| Tahap | Isi | Sumber Next.js | Di roadmap lama | Status |
|---|---|---|---|---|
| M0 | Fondasi Laravel, Inertia, koneksi Supabase, health check | - | M0 | Selesai |
| M1 | Login/logout, Supabase Auth, sesi, user management, role/permission, scope company/outlet, audit, rate limit login, header keamanan | `lib/auth`, `lib/security`, `lib/supabase`, `login`, `auth`, `admin/users`, `admin/roles`, `admin/audit` | M1 + sebagian M7 | **DONE — Development Verified** (3 November) |
| M2 | Kategori, produk, harga, modifier, foto, sold out, menu customer `/order` dengan keranjang (checkout di M3) | `lib/catalog`, `admin/products`, `admin/categories`, `admin/modifiers`, `order` | M2 | **DONE — Development Verified** (3 November) |
| M3 | POS, QR self-order, kiosk, checkout, status order, idempotency | `lib/orders`, `pos`, `order`, `kiosk`, `api/orders` | sebagian M3 | **Siap review** (3 November, Development Verified, belum di-commit) |
| M4 | Pembayaran cash, antrean terpadu, nomor antrean, KDS, realtime atau polling | `lib/orders` (pembayaran, KDS), `kds` | sebagian M3 | Belum mulai |
| M5 | Stok masuk/keluar, opname, waste, ledger, ketersediaan | `lib/inventory` | M5 | Belum mulai |
| M6 | Sesi kas, refund, rekonsiliasi, laporan, dashboard | `lib/cash`, `lib/reports` | M4 | Belum mulai |
| M7 | Karyawan, GPS, check-in/out, tugas luar, laporan absensi, abstraksi provider biometrik | `lib/attendance`, `staff` | M6 | Belum mulai |
| M8 | Hardening: keamanan, performa, PWA, scheduler, rate limit, monitoring, backup/restore | - | M8 | Belum mulai |
| M9 | Paritas penuh, staging, UAT outlet, cutover production | - | M9 | Belum mulai |

Penyesuaian yang perlu dicatat:

- **Manajemen user, role, dan audit masuk M1**, bukan M7 seperti roadmap lama, karena M1 owner mencakup user management dan audit.
- **Kas dan laporan pindah dari M4 ke M6.** Di Next.js modul ini dibangun setelah order, jadi urutan dependensinya tetap.
- **POS dan KDS dipisah** (M3 dan M4). Pembayaran cash memicu nomor antrean dan masuk KDS, jadi M4 tidak bisa dimulai sebelum M3.
- **Reset password staf oleh admin (GL-5)** dikerjakan di M1 karena bagian dari user management. Fitur ini belum ada di Next.js.

## Prinsip

1. Yang dipindahkan adalah perilaku yang sudah terbukti test, bukan baris kode. Setiap modul selesai bila test padanannya lolos. Lihat [LARAVEL_FEATURE_PARITY.md](LARAVEL_FEATURE_PARITY.md).
2. Database tidak ditulis ulang. Laravel memakai tabel, enum, constraint, trigger, dan `pg_cron` yang sama. Lihat [LARAVEL_DATABASE_MAPPING.md](LARAVEL_DATABASE_MAPPING.md).
3. Jaminan transaksi tetap di PostgreSQL (constraint, trigger, `FOR UPDATE`), bukan hanya validasi PHP.
4. Satu modul pada satu waktu, dilaporkan sebelum modul berikutnya.
5. Next.js tidak dihapus sampai cutover.

## M1: yang dibangun

**Autentikasi.** Login memanggil `POST /auth/v1/token`. Token dari Supabase diverifikasi lokal sebelum dipakai: tanda tangan ES256 dengan JWKS, `exp`, `iss`, `aud`, `role`, dan `sub` harus sama dengan user. Algoritma lain, termasuk `none` dan HS256, ditolak. Kunci baru setelah rotasi diambil sekali per menit, jadi token dengan `kid` acak tidak bisa memaksa panggilan JWKS terus-menerus.

**Sesi.** Cookie sesi Laravel terenkripsi, HttpOnly, dan SameSite=Lax. Id sesi diganti saat login dan dibuang saat logout. Sesi hanya menyimpan id session Supabase. Token Supabase disimpan terenkripsi di store terpisah (`AUTH_TOKEN_CACHE_DRIVER`) dan tidak pernah dikirim ke browser.

Store terpisah dipilih karena request paralel dalam satu sesi bisa saling menimpa refresh token. Kalau token lama yang tersimpan, pemakaian ulangnya setelah masa toleransi membuat Supabase mencabut seluruh session. Refresh dikunci per session.

**Pemeriksaan setiap request:**

1. Token disegarkan 60 detik sebelum kedaluwarsa.
2. JWT diverifikasi.
3. `auth.sessions` diperiksa, agar logout atau pencabutan langsung berlaku.
4. `users.is_active` dan `auth.users.banned_until` diperiksa.
5. Grants dimuat dari DB.

Bila Supabase tidak terjangkau dan token sudah kedaluwarsa, server menjawab 503 tanpa menghapus sesi, sehingga staf tidak perlu login ulang saat jaringan pulih.

**Otorisasi.** Gate dibuat untuk setiap permission dan menerima scope outlet. `UserPolicy::dominate` dan `RolePolicy` menegakkan aturan anti eskalasi yang sama dengan `admin-ops.ts`. Middleware `AuthorizeRoute` memakai peta area yang sama dengan `ROUTE_ACCESS`, dan halaman terlarang dijawab 403 sungguhan.

**Lain-lain.**

- Rate limit login di `rate_limit_counters` memakai aturan yang sama dengan Next.js.
- Audit memakai `clock_timestamp()`.
- Header keamanan ditambah CSP berbasis nonce (SEC-014 kini tertutup).
- Riwayat Inertia dienkripsi dan dibersihkan saat logout, agar tombol Back di perangkat outlet tidak menampilkan data akun sebelumnya.
- Log query tanpa nilai parameter.

## M3: yang dibangun

**Order engine.** `OrderEngine::create` dipakai semua channel. Harga dihitung ulang dari DB lewat `Storefront::quote`. Order, item, modifier, tagihan `payments` berstatus `pending`, audit `order.created`, dan sinyal realtime ditulis dalam satu transaksi. Duplikat dicegah oleh `insert … on conflict (outlet_id, idempotency_key) do nothing`. Retry dibandingkan memakai `request_hash`, yang algoritmanya sama persis dengan Next.js. Tidak ada tabel atau migration baru.

**Kedaluwarsa.** `expires_at = now() + TTL outlet` dihitung dengan waktu DB. Pembatalan tetap dikerjakan job pg_cron `sudaya-expire-pending-orders` yang sudah ada. Laravel tidak menambah scheduler.

**Checkout publik.** `POST /order/checkout` dipanggil lewat JSON fetch dengan CSRF, dan origin diperiksa. Rate limit per perangkat (cookie `sdy_did`) dan per IP tepercaya atau per outlet. Token status disimpan di cookie HttpOnly per order, kecuali di kiosk.

**Halaman.** `/order` (QR/web), `/order/status/{id}`, `/kiosk`, `/pos`, `/pos/orders`, `/pos/orders/{id}`, `/pos/orders/{id}/receipt`. Status guest dan daftar staf memakai polling sampai realtime M4.

**Belum di M3 (sengaja).** Konfirmasi tunai, nomor antrean, KDS, sesi kas, pembatalan, dan realtime browser. Order M3 tetap `pending_payment` sampai M4.

## Pengujian

| Lapis | Perintah | Isi |
|---|---|---|
| Unit dan feature | `php artisan test` | Tanpa database: RBAC, aturan akses, verifikasi JWT, batas login, header, halaman error, log |
| Live | `php artisan test -c phpunit.live.xml --testsuite Live` | Supabase dev sungguhan, fixture terisolasi per run, dihapus di akhir |
| E2E | `php artisan test -c phpunit.live.xml --testsuite E2E` | Server PHP sungguhan, cookie, CSRF aktif |
| Browser | `probe.mjs` (Chrome headless) | 3 ukuran layar, error console, CSP, tombol Back setelah logout |

Fixture memakai prefix `test-<runId>-` dan email `sudaya-test-<runId>-`, sama dengan Next.js. Data run yang terputus dibersihkan dengan `php tests/Live/bin/fixture.php cleanup <runId>`. Test live tidak boleh diarahkan ke database production.

## Kecepatan

M1 selesai dalam satu sesi kerja (2–3 November), termasuk test dan dokumen. Bagian terlama adalah test live, karena latensi laptop ke database eu-west-1 tinggi: 2,6 detik untuk koneksi dan 0,6 detik per query. M2 dan M3 masing-masing selesai dalam satu sesi kerja (3 November). Belum ada perkiraan tanggal untuk M4–M9.

Pengukuran M3 dari laptop: checkout 12 query, tetap sama berapa pun jumlah item, sekitar 7 detik karena tiap query ±0,59 detik ke eu-west-1. Ini bukan angka production. Ukur ulang dari hosting yang satu region dengan database sebelum go-live.

## Risiko

| Risiko | Mitigasi |
|---|---|
| Aturan bisnis hilang saat ditulis ulang | Test Next.js menjadi daftar periksa per modul |
| Dua aplikasi menulis ke database yang sama | Laravel hanya memakai data test sampai cutover |
| Worker PHP menghabiskan slot pooler (15 klien session pooler) | Transaction pooler dan uji beban di M8 |
| Latensi tinggi bila hosting jauh dari database | Hosting dan Supabase production satu region |
| Prepared statement di transaction pooler | `DB_EMULATE_PREPARES=true`, diuji di M8 sebelum dipercaya |
