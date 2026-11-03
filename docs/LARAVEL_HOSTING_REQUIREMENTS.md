# Kebutuhan hosting Laravel

Disusun 3 November 2026. Isi dokumen ini teknis. Harga paket dan nama penyedia lokal sengaja tidak dicantumkan; keduanya harus dicek langsung ke penyedia sebelum owner memutuskan (EXT-029).

## Profil beban Sudaya Coffee

Pilot satu outlet:

- Sekitar 20 akun staf, 2–4 perangkat aktif bersamaan (POS, KDS, admin).
- Order pelanggan lewat QR, puncak di jam ramai.
- KDS memakai Supabase Realtime dari browser. Bila realtime terputus, cadangannya polling (M4).

Bebannya kecil. Yang menentukan pilihan hosting adalah tiga hal berikut, bukan CPU.

1. **Jarak ke database.** Dari laptop di Indonesia ke Supabase dev di eu-west-1 terukur 2,6 detik untuk membuka koneksi dan 0,6 detik per query. Satu halaman admin berisi sekitar 8 query. Hosting harus satu region dengan Supabase production.
2. **Koneksi database per worker PHP.** PHP membuka koneksi baru di setiap request. Session pooler Supabase dev dibatasi 15 klien.
3. **Kemampuan menjalankan proses dan koneksi keluar.** Hosting harus bisa membuka koneksi Postgres ke Supabase (port 5432 atau 6543) dan HTTPS ke Supabase Auth.

## Kebutuhan minimum

| Kebutuhan | Nilai | Sumber atau alasan |
|---|---|---|
| PHP | 8.3 ke atas, disarankan 8.5 | Laravel 13 |
| Ekstensi wajib Laravel | ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo, session, tokenizer, xml | Dokumentasi deployment Laravel 13 |
| Ekstensi tambahan | `pdo_pgsql`, `intl`, `sodium`, `zip` | Postgres, format tanggal/angka, Composer |
| CA bundle terbaru | Wajib | Tanpa bundle, panggilan HTTPS ke Supabase gagal (cURL error 60, terbukti di lokal) |
| Koneksi keluar | TCP 5432/6543 ke pooler Supabase, HTTPS 443 | Banyak shared hosting memblokir port database keluar |
| HTTPS | Wajib | Cookie Secure, kamera dan GPS, enkripsi riwayat Inertia |
| Direktori bisa ditulis | `storage/`, `bootstrap/cache` | Sesi dan store token disimpan sebagai file |
| Composer | Di server, atau vendor dibangun di CI lalu diunggah | |
| Node.js | Tidak di server | Aset dibangun di CI/lokal (`npm run build`) lalu `public/build` diunggah |
| Cron | Belum wajib di M1 | Kedaluwarsa order dan pembersihan rate limit berjalan di `pg_cron` |
| Worker atau queue | Tidak | `QUEUE_CONNECTION=sync` |
| WebSocket server | Tidak | Realtime langsung dari browser ke Supabase |
| Memori | 128–256 MB per proses PHP | Laravel standar. Diukur ulang di M8 |
| `php.ini` production | `display_errors=Off`, `zend.exception_ignore_args=On`, `expose_php=Off` | Stack trace tanpa argumen fungsi |
| Batas upload | `upload_max_filesize` minimal `2M` (foto menu), `post_max_size` minimal `8M` | Batas 2 MB foto ditegakkan aplikasi; bukti foto inventory nanti 3 MB |
| Jumlah server | Satu | Sesi dan store token berupa file. Lebih dari satu server butuh store bersama (database atau Redis) |

## Perbandingan

| Aspek | Shared hosting PHP | Managed Laravel (mis. Laravel Cloud) | VPS kecil + Supabase |
|---|---|---|---|
| PHP 8.3+ dan ekstensi | Tergantung paket, harus dicek satu per satu | Ya | Ya, diatur sendiri |
| Koneksi keluar ke Postgres Supabase | **Sering diblokir**, wajib dites sebelum membeli | Ya | Ya |
| Region dekat Indonesia | Banyak penyedia lokal | Singapura tersedia di daftar region | Singapura atau Jakarta, tergantung penyedia |
| SSH dan Composer | Sebagian | Ya (deploy dari Git) | Ya |
| Cron | Biasanya ada | Ya | Ya |
| Worker jangka panjang | Umumnya tidak | Ya | Ya |
| Kendali `php.ini`, header, TLS | Terbatas | Sedang | Penuh |
| Deploy dan rollback | Manual (upload) | Bawaan | Skrip sendiri atau Forge |
| Beban perawatan | Rendah | Paling rendah | Tertinggi: patch OS, firewall, backup server |
| Risiko utama | Port Postgres diblokir, versi PHP tertinggal | Biaya dan lokasi data perlu dicek | Keamanan server menjadi tanggung jawab sendiri |

## Rekomendasi teknis

1. **Pindahkan Supabase production ke Singapura (`ap-southeast-1`)**, region Supabase terdekat ke Indonesia, dan taruh hosting di region yang sama. Project dev di eu-west-1 tidak dipakai untuk production (EXT-028).
2. **Pilihan utama: managed Laravel atau VPS di Singapura.** Keduanya memenuhi semua kebutuhan tanpa pengecualian. Managed Laravel lebih sedikit perawatannya. VPS lebih murah tetapi menambah tugas keamanan server.
3. **Shared hosting hanya bila lolos uji ini sebelum membayar penuh:**
   - `php -v` 8.3 atau lebih.
   - `php -m` memuat `pdo_pgsql`.
   - Koneksi ke pooler Supabase dari server berhasil, misalnya `php artisan tinker` lalu `DB::select('select 1')`.
   - HTTPS ke Supabase berhasil dari PHP.
   - Ada SSH dan Composer.
4. **Pakai Transaction pooler (port 6543)** dengan `DB_EMULATE_PREPARES=true` bila jumlah worker PHP bisa melebihi slot session pooler. Mode ini harus diuji di M8, termasuk `FOR UPDATE` dan `set_config(..., true)` di transaksi.

## Yang tidak boleh diasumsikan

- Shared hosting bisa menjalankan scheduler, worker, atau koneksi Postgres keluar.
- Hosting sudah menyediakan CA bundle yang benar.
- Latensi lokal mencerminkan production.

## Sumber

- [Laravel 13 Deployment: server requirements](https://laravel.com/docs/13.x/deployment)
- [Supabase: Available regions](https://supabase.com/docs/guides/platform/regions)
- [Laravel Cloud API: list regions](https://laravel.com/cloud/docs/api/meta/list-regions)
