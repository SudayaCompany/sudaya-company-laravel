# Pemetaan database Drizzle ke Laravel

Diaudit 2 November 2026 pada Supabase dev dengan query hanya-baca. Hasil mentah ada di luar repo (`qa-logs/m1-laravel/db-audit.json`).

## Kondisi database

| Hal | Isi |
|---|---|
| Server | PostgreSQL 17.11, timezone UTC |
| Extension | `pg_cron` 1.6.4, `pgcrypto`, `uuid-ossp`, `pg_stat_statements`, `supabase_vault` |
| Tabel `public` | 41, semuanya RLS aktif tanpa policy. Publishable key tidak bisa membaca apa pun lewat REST |
| Enum | 18, antara lain `order_status`, `payment_status`, `inventory_transaction_type`, `cash_movement_type`, `approval_type` |
| Fungsi | `audit_logs_immutable()`, `inventory_ledger_immutable()`, `expire_pending_orders(p_limit, p_order_id)` |
| Trigger | Tolak UPDATE, DELETE, TRUNCATE di `audit_logs` dan `inventory_transactions`. DELETE hanya dengan `set_config('sudaya.*_maintenance','on', true)` di transaksi yang sama |
| `pg_cron` | `sudaya-expire-pending-orders` tiap menit, `sudaya-prune-rate-limits` tiap 10 menit |
| Migration | 10 baris di `drizzle.__drizzle_migrations` |
| Akses `auth` | User DB bisa membaca `auth.sessions` dan `auth.users`, dipakai untuk cek session dan email |

## Model Laravel M1

Semua tabel memakai nama, kolom, dan constraint yang sudah ada. Tidak ada tabel baru.

| Tabel | Model | Kunci | Catatan |
|---|---|---|---|
| `companies` | `Company` | UUID, `HasUuids` | `code` unik |
| `brands` | `Brand` | UUID | unik `(company_id, code)` |
| `outlets` | `Outlet` | UUID | check radius 1–1000, TTL 1–1440, koordinat valid |
| `users` | `User` (Authenticatable) | UUID = `auth.users.id`, tidak auto | `email` dan `banned_until` dari join `auth.users`. FK cascade dari `auth.users` |
| `roles` | `Role` | UUID | unik `(company_id, code)` |
| `permissions` | `Permission` | `code` text | |
| `role_permissions` | pivot `Role::permissions()` | PK `(role_id, permission_code)` | cascade dari role dan permission |
| `user_roles` | Query Builder | UUID | unik `NULLS NOT DISTINCT (user_id, role_id, outlet_id)`, `outlet_id` null = semua outlet |
| `audit_logs` | `AuditLog`, hanya baca | UUID | tulis lewat `AuditLogger` dengan `clock_timestamp()` |
| `rate_limit_counters` | Query Builder (SQL upsert) | `key` sha256 | dibersihkan `pg_cron` |

### M2 (katalog)

Diaudit 3 November 2026. Semua tabel sudah ada; **tidak ada tabel, kolom, atau migration baru.**

| Tabel | Akses Laravel | Aturan DB yang dipertahankan |
|---|---|---|
| `categories` | Query Builder di `CatalogAdmin` | Unik `(brand_id, slug)` hanya untuk baris `deleted_at is null`; soft delete lewat `deleted_at` |
| `products` | Query Builder | Unik `(brand_id, slug)` dan `(brand_id, sku)` hanya untuk baris aktif; `image_path` berisi path Storage, bukan URL; `order_items.product_id` merujuk tanpa cascade sehingga produk tidak pernah dihapus fisik |
| `outlet_products` | upsert per `(outlet_id, product_id)` | `is_available` manual, `price_override`, `inventory_item_id` untuk produk jadi |
| `modifier_groups` | Query Builder | enum `modifier_selection` (`single`, `multiple`) |
| `modifiers` | Query Builder | cascade dari grup; `order_item_modifiers` menyimpan snapshot nama dan harga, jadi opsi boleh dihapus |
| `product_modifier_groups` | Query Builder | PK `(product_id, group_id)`, cascade dari produk dan grup |
| `inventory_items` | hanya dibaca untuk ketersediaan | `stock_qty >= 0` (check constraint) |

Pelanggaran unique index (`23505`) diterjemahkan ke pesan yang sama dengan Next.js berdasarkan nama constraint.

**Supabase Storage:** bucket `menu-images` publik untuk dibaca (batas 2 MB, hanya JPG/PNG/WebP di level bucket). `inventory-evidence` dan `attendance-evidence` privat. `storage.objects` tidak punya policy untuk anon atau authenticated, jadi hanya service role di server yang bisa menulis. Tidak ada bucket baru.

Pola untuk modul berikutnya:

- **Operasi transaksi** (order, pembayaran, stok, kas) memakai `DB::transaction` dan Query Builder dengan `lockForUpdate()` atau `sharedLock()`, sama dengan `FOR UPDATE` dan `FOR SHARE` di Drizzle.
- **Enum PostgreSQL** dibaca sebagai string. Nilainya divalidasi dengan `Rule::in` dari daftar yang sama dengan enum.
- **Uang** (`bigint` rupiah) di-cast `integer`. **Kuantitas dan harga pokok** (`numeric`) tetap string desimal, bukan float.
- **`created_at`** default DB `now()`. `updated_at` diisi aplikasi, sama seperti Drizzle (`$onUpdate`). Tidak ada soft delete di schema saat ini.
- **Tenant scope** (`company_id`, `brand_id`, `outlet_id`) selalu diisi dari actor dan outlet yang tervalidasi, tidak pernah dari input client.

## Mekanisme migration resmi

**Keputusan:** mulai M2, perubahan schema baru ditulis sebagai migration Laravel di `database/migrations`. Drizzle dibekukan bersama Next.js.

Aturannya:

1. **Migration lama tidak di-replay.** Schema yang ada adalah baseline, dan 10 migration Drizzle tetap tercatat di `drizzle.__drizzle_migrations`.
2. **Tabel `migrations` Laravel belum ada.** Tabel itu dibuat pada `php artisan migrate` pertama. Migration pertama wajib menjalankan `alter table public.migrations enable row level security`, karena tabel baru di `public` tanpa RLS bisa dibaca lewat REST Supabase.
3. **Hanya additive.** Migration hanya menambah: kolom nullable atau ber-default, tabel baru dengan RLS aktif, index `concurrently` bila tabel besar. Tidak ada `migrate:fresh`, `migrate:reset`, `db:wipe`, atau `migrate:rollback` di database bersama.
4. **Fitur khusus PostgreSQL ditulis apa adanya** dengan `DB::statement`, misalnya enum, partial unique index, trigger, dan `pg_cron`.
5. **Migration bawaan Laravel tetap dihapus.** Tabel default seperti `users`, `cache`, `sessions`, dan `jobs` bentrok atau tidak dipakai.
6. **Sebelum dijalankan,** `php artisan migrate --pretend` dibaca dulu. Migration ke production hanya dengan persetujuan owner.

M1 tidak membutuhkan perubahan schema, jadi belum ada migration Laravel yang dijalankan.
