# Runbook deployment staging Laravel

Disusun 4 November 2026. **Belum ada deployment.** Runbook ini berlaku setelah hosting dipilih ([STAGING_HOSTING_PLAN.md](STAGING_HOSTING_PLAN.md)) dan Supabase staging dibuat ([SUPABASE_STAGING_PLAN.md](SUPABASE_STAGING_PLAN.md)).

Tanda di kolom "Jenis":
- **Otomatis**: bisa dijalankan pengelola deploy/skrip tanpa keputusan baru.
- **Persetujuan owner**: wajib persetujuan tertulis owner sebelum dijalankan.

## 1. Persiapan hosting

| Langkah | Jenis |
|---|---|
| Owner memilih penyedia, membuat akun, membayar | Persetujuan owner |
| Uji kelayakan: `php -v` ≥ 8.3, `php -m` memuat `pdo_pgsql`, `intl`, `sodium`; koneksi keluar ke pooler Supabase; HTTPS ke Supabase dari PHP; SSH + Composer | Otomatis |
| Siapkan struktur rilis: `releases/<tanggal>`, `shared/storage`, `shared/.env`, symlink `current` | Otomatis |
| Document root ke `current/public` | Otomatis |

## 2. Koneksi repository

| Langkah | Jenis |
|---|---|
| Tentukan sumber kode: repo Git hosting yang disetujui, atau unggah artefak build. Repo Laravel saat ini hanya lokal, belum ada remote | Persetujuan owner (push ke remote) |
| Deploy key read-only untuk server bila memakai Git | Persetujuan owner |
| Deploy hanya dari commit yang sudah disetujui (M4 final, bukan commit WIP) | Persetujuan owner |

## 3. PHP dependencies

| Langkah | Jenis |
|---|---|
| `composer install --no-dev --optimize-autoloader` di CI/mesin build atau di server | Otomatis |
| Pastikan CA bundle sistem terbaru (cURL ke Supabase berhasil) | Otomatis |

## 4. Build frontend

| Langkah | Jenis |
|---|---|
| `npm ci && npm run build` di CI/mesin build (Node 22), unggah `public/build` | Otomatis |
| Realtime memakai `SUPABASE_URL` dan publishable key dari server (props Inertia), jadi tidak ada secret di bundle JS | — |

## 5. Environment configuration

| Langkah | Jenis |
|---|---|
| Isi `shared/.env` dari password manager sesuai tabel di [SUPABASE_STAGING_PLAN.md](SUPABASE_STAGING_PLAN.md) §Environment | Persetujuan owner (akses secret) |
| `php artisan key:generate --show` → simpan di password manager → isi `APP_KEY` | Otomatis |
| `php.ini`: `display_errors=Off`, `zend.exception_ignore_args=On`, `expose_php=Off`, `upload_max_filesize=2M`+, `post_max_size=8M`+ | Otomatis |
| `php artisan optimize` di direktori rilis | Otomatis |

## 6. Database connection

| Langkah | Jenis |
|---|---|
| `GET /api/health` mengembalikan sukses (database terjangkau) | Otomatis |
| Catat waktu koneksi dan query dari server ke Supabase staging (`php artisan tinker`: `select 1` dua kali) sebagai baseline latensi | Otomatis |

## 7. Migration review

| Langkah | Jenis |
|---|---|
| Schema staging dibuat dari 10 migration Drizzle, setelah dibaca | Persetujuan owner |
| `php artisan migrate` **tidak dijalankan** (Laravel M1–M4 tanpa migration). Bila nanti ada, baca `--pretend` dulu dan pastikan migration pertama mengaktifkan RLS di tabel `migrations` | Persetujuan owner |
| Seed (`php artisan db:seed`) dan bootstrap Super Admin | Persetujuan owner |
| Verifikasi checklist pasca-migration di SUPABASE_STAGING_PLAN | Otomatis |

## 8. Scheduler

| Langkah | Jenis |
|---|---|
| Pastikan 2 job pg_cron aktif di staging (`select jobname, schedule, active from cron.job`) | Otomatis |
| Uji kedaluwarsa: buat order QR, jangan bayar, tunggu lewat TTL outlet (default 15 menit) → status berubah "Dibatalkan" dengan alasan batas waktu | Otomatis (bagian UAT) |
| Cron server (`schedule:run`) belum diperlukan | — |

## 9. HTTPS

| Langkah | Jenis |
|---|---|
| Arahkan DNS subdomain staging ke hosting | Persetujuan owner (DNS) |
| Pasang sertifikat otomatis, paksa HTTPS | Otomatis |
| Cek di browser: HSTS, CSP, cookie `Secure`+`HttpOnly`, tanpa peringatan mixed content | Otomatis |

## 10. Smoke test (pengelola deploy, ±30 menit)

| No | Cek | Hasil yang diharapkan |
|---|---|---|
| 1 | `GET /up`, `GET /api/health` | 200 |
| 2 | Login Super Admin, buka `/admin/audit` | `auth.login` tercatat |
| 3 | Buat kategori + 1 produk tanpa foto dan 1 dengan foto | Tampil di `/order?outlet=<kode>` |
| 4 | Buka kas Rp 0 di `/pos/cash`, jual 1 item tunai di `/pos` | Nomor antrean `<prefix>-001`, kembalian benar |
| 5 | Buka `/kds` di perangkat lain | Indikator "Realtime", order muncul tanpa refresh |
| 6 | Selesaikan order di KDS, cek struk | Struk lunas, print 80 mm |
| 7 | Tutup kas dengan uang fisik = saldo | "Uang cocok" |
| 8 | Log `storage/logs` | Tanpa error |

Kegagalan smoke test → jangan lanjut ke UAT, rollback atau perbaiki dulu.

## 11. UAT

| Langkah | Jenis |
|---|---|
| Siapkan data master sesuai [MASTER_DATA_PREPARATION.md](MASTER_DATA_PREPARATION.md) | Owner/manager |
| Jalankan [SUDAYA_OUTLET_UAT.md](SUDAYA_OUTLET_UAT.md) dengan kasir, barista, manager di perangkat outlet | Manager |
| Defect UAT dicatat; perbaikan lewat siklus QA biasa, lalu deploy ulang ke staging | Otomatis |
| Tanda tangan penerimaan Manager dan Owner | Persetujuan owner |

## 12. Rollback

| Situasi | Tindakan | Jenis |
|---|---|---|
| Rilis kode bermasalah | Kembalikan symlink `current` ke rilis sebelumnya, `php artisan optimize` | Otomatis |
| Konfigurasi salah | Pulihkan `shared/.env` versi sebelumnya dari password manager | Otomatis |
| Data rusak di staging | Restore backup ke project baru, arahkan `DB_URL` ke sana | Persetujuan owner |
| Migration (bila nanti ada) | Hanya migration additive; rollback = kode saja, schema tidak dibalik | Persetujuan owner |

Rollback dilatih minimal sekali di staging sebelum production.
