# Rencana Supabase staging (Singapura)

Disusun 4 November 2026. **Belum ada project staging yang dibuat.** Pembuatan project, pemilihan paket, dan pembayaran dilakukan owner (EXT-028).

## Prinsip

- Tiga project terpisah:
  - **development** (eu-west-1, sudah ada, dipakai test otomatis yang membuat dan menghapus data);
  - **staging** (Singapura, untuk UAT dan latihan deploy);
  - **production** (Singapura).
- Staging dibangun dari migration yang tercatat, **bukan** salinan database development. User, password, sesi, dan data uji development tidak boleh dipindah.
- Test otomatis (`php artisan test -c phpunit.live.xml`) **tidak boleh** diarahkan ke staging maupun production. Test itu membuat lalu menghapus company dan user.
- Secret (password database, service role key) hanya disimpan di password manager dan panel hosting, tidak di Git.

## Pembuatan project

| Langkah | Siapa | Catatan |
|---|---|---|
| Buat project baru, region `ap-southeast-1` (Singapura) | Owner | Catat password database di password manager |
| Pilih paket yang memenuhi backup harian dan, bila dibutuhkan, point-in-time recovery | Owner | Fitur backup per paket harus dicek di dashboard/penawaran; tidak diasumsikan |
| Catat `SUPABASE_URL`, publishable key, service role key, connection string session pooler (5432) dan transaction pooler (6543) | Owner → password manager | Diserahkan ke pengelola deploy lewat password manager, bukan chat |

## Checklist pembuatan dan kontrol identitas project

Tujuan utama checklist ini: perintah migration, seed, dan bootstrap **tidak pernah** mengenai project development atau production karena salah connection string.

| No | Kontrol | Cara memeriksa | Paraf |
|---|---|---|---|
| 1 | Nama project jelas, mis. `sudaya-staging` | Dashboard Supabase | |
| 2 | Region `ap-southeast-1` (Singapura) | Dashboard → Project Settings → General | |
| 3 | Project ref staging dicatat di password manager bersama URL dan key-nya, terpisah dari dev dan production | Password manager | |
| 4 | Sebelum setiap perintah, host `DB_URL` memuat `ap-southeast-1` dan username pooler memuat project ref staging, **bukan** ref dev (dev ada di `eu-west-1`) | Baca `DB_URL` di terminal sebelum menjalankan; jangan memakai `.env` development | |
| 5 | `SUPABASE_URL` = `https://<ref-staging>.supabase.co` | Sama dengan nomor 3 | |
| 6 | Database masih kosong sebelum migration (tidak ada tabel `public.companies`) | `select to_regclass('public.companies')` → `null` | |
| 7 | Terapkan 10 migration, lalu verifikasi tabel di §Verifikasi | Lihat di bawah | |
| 8 | **RLS**: `select count(*) from pg_tables where schemaname = 'public' and not rowsecurity` → `0` | SQL editor | |
| 9 | **Tanpa policy** anon/authenticated di tabel bisnis: `select count(*) from pg_policies where schemaname = 'public'` → `0` | SQL editor | |
| 10 | **pg_cron**: `select jobname, schedule, active from cron.job` → tepat 2 job aktif | SQL editor | |
| 11 | Fungsi expiry tidak bisa dipanggil publik: `has_function_privilege('anon', 'public.expire_pending_orders(integer, uuid)', 'execute')` → `false` | SQL editor | |
| 12 | Storage: 3 bucket sesuai §Verifikasi | Dashboard → Storage | |
| 13 | Auth: pendaftaran publik mati, Site URL dan Redirect URL staging | Dashboard → Authentication | |
| 14 | **Backup** aktif sesuai paket; frekuensi dan masa simpan dicatat | Dashboard → Database → Backups | |
| 15 | **Uji restore**: pulihkan ke project terpisah, lalu ulangi nomor 8–10 di hasil restore dan login admin | Project restore | |
| 16 | Seed + Super Admin pertama, lalu pastikan tidak ada company `test-*` atau email `sudaya-test-*` | SQL editor | |
| 17 | Kredensial staging tidak pernah dimasukkan ke `.env` laptop yang dipakai test otomatis | Pemeriksaan pengelola | |

## Schema

Sumber schema satu-satunya adalah 10 migration Drizzle di repo Next.js, `drizzle/0000_init.sql` sampai `0009_cash_movements.sql`. Laravel M1–M4 tidak menambah migration.

| Langkah | Perintah | Persetujuan |
|---|---|---|
| Review isi migration, terutama `0004_ops_functions.sql` (fungsi + pg_cron), `0007_audit_logs_immutable.sql` (trigger audit), `0009_cash_movements.sql` (kas, unique index sesi terbuka) | Baca file | — |
| Terapkan migration ke staging | `npm run db:migrate` di repo Next.js, dengan environment staging terpisah dan session pooler | Owner |
| Buat bucket Storage | `npm run storage:setup` (repo Next.js) | Owner |
| Seed role, permission, company/brand/outlet awal | `php artisan db:seed` (repo Laravel) | Owner |
| Super Admin pertama | Buat user di Supabase Dashboard → `php artisan sudaya:admin-bootstrap --email=... --name="..."` | Owner |

### Verifikasi setelah migration

| Pemeriksaan | Hasil yang diharapkan |
|---|---|
| `drizzle.__drizzle_migrations` | 10 baris |
| Extension | `pg_cron`, `pgcrypto`, `uuid-ossp` aktif |
| RLS | Semua tabel `public` `rowsecurity = true`, tanpa policy untuk anon/authenticated |
| Fungsi | `public.expire_pending_orders(integer, uuid)` ada, `EXECUTE` dicabut dari `PUBLIC`, `anon`, `authenticated` |
| Trigger | Trigger audit append-only aktif; DELETE `audit_logs` ditolak tanpa flag maintenance |
| pg_cron | Tepat 2 job aktif: `sudaya-expire-pending-orders` (`* * * * *`) dan `sudaya-prune-rate-limits` (`*/10 * * * *`) |
| Index penting | `cash_sessions_one_open_per_outlet`, `orders (outlet_id, idempotency_key)`, `orders (outlet_id, business_date, queue_number)` unik |
| Storage | Bucket `menu-images` (publik baca, 2 MB, JPG/PNG/WebP), `inventory-evidence` dan `attendance-evidence` privat; `storage.objects` tanpa policy anon/authenticated |
| Data | Tidak ada company `test-*` atau email `sudaya-test-*` |

## Supabase Auth

| Pengaturan | Nilai |
|---|---|
| Site URL | `https://staging.<domain>` |
| Redirect URL | `https://staging.<domain>/auth/confirm` |
| Pendaftaran publik | Dimatikan; staf masuk lewat undangan dari `/admin/users` |
| Rate limit token | Dicatat nilainya (EXT-027) |
| SMTP untuk email undangan/reset | Bawaan Supabase punya batas kirim; untuk UAT dengan banyak staf, pertimbangkan SMTP sendiri (keputusan owner) |

## Realtime

Tidak ada pengaturan khusus. Aplikasi mengirim sinyal tanpa data lewat `realtime.send(..., private => false)` di dalam transaksi. Browser berlangganan dengan publishable key. RLS bisnis tidak dibuka untuk realtime. Yang perlu dicek di staging: WebSocket ke `wss://<project>.supabase.co` tidak diblokir jaringan outlet.

## Environment aplikasi staging

Diisi di panel hosting. Rinciannya di [LARAVEL_DEPLOYMENT_GUIDE.md](LARAVEL_DEPLOYMENT_GUIDE.md) §2.

| Variabel | Staging |
|---|---|
| `APP_ENV` | `production`, agar perilaku sama dengan production: HSTS hanya aktif di `production`, cookie token pesanan dipaksa `Secure`, dan test live serta skrip fixture menolak berjalan |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Baru, khusus staging |
| `APP_URL` | `https://staging.<domain>` |
| `DB_URL` | Session pooler staging, `sslmode=require` |
| `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, `SUPABASE_SERVICE_ROLE_KEY` | Dari project staging |
| `SESSION_SECURE_COOKIE` | `true` |
| `TRUSTED_IP_HEADER` | Sesuai proxy hosting, kosong bila tidak yakin |

> Catatan: `tests/Live/LiveTestCase.php`, `tests/Live/bin/fixture.php`, dan `order-op.php` berhenti bila `APP_ENV=production`. Staging tidak boleh memakai kredensial yang sama dengan file `.env` development di laptop.

## Backup

| Butir | Rencana | Status |
|---|---|---|
| Backup otomatis | Sesuai paket staging; catat frekuensi dan masa simpan | Belum |
| Uji restore | Pulihkan backup ke project terpisah (bukan menimpa staging), cek jumlah tabel, 2 job pg_cron, dan login admin | Belum |
| Ekspor schema | `pg_dump --schema-only` dari staging untuk arsip perbandingan dengan production | Belum |

## Data uji staging

- Dibuat manual saat UAT: menu contoh atau menu asli, akun staf UAT per peran (kasir, barista, manager, owner).
- Tidak memakai data karyawan asli kecuali disetujui owner.
- Order dan transaksi UAT dibiarkan sebagai bukti UAT; staging di-reset hanya dengan membuat project baru, tidak dengan `db:wipe`.
