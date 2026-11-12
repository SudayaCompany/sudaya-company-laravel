<?php

namespace App\Services\System;

use App\Services\Seed\InitialData;
use App\Services\Storage\StorageBuckets;
use App\Support\Access\Rbac;
use App\Support\Database\BaselineSchema;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pemeriksaan kesehatan sistem untuk go-live dan perawatan rutin (`php artisan sudaya:doctor`).
 * Hanya membaca: tidak ada data, schema, atau bucket yang diubah.
 */
final class SystemHealth
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** File yang disentuh scheduler Laravel tiap 5 menit (routes/console.php). */
    public const HEARTBEAT = 'framework/schedule-heartbeat';

    /** @var list<array{group: string, label: string, status: string, message: string, hint: ?string}> */
    private array $checks = [];

    public function __construct(private StorageBuckets $buckets) {}

    /** @return list<array{group: string, label: string, status: string, message: string, hint: ?string}> */
    public function run(): array
    {
        $this->checks = [];
        $this->guard('Konfigurasi', 'Konfigurasi', fn () => $this->configuration());
        $this->guard('Server', 'Server', fn () => $this->server());
        $connected = false;
        $this->guard('Database', 'Koneksi', function () use (&$connected) {
            $connected = $this->connection();
        });
        if ($connected) {
            $this->guard('Database', 'Schema', fn () => $this->schema());
            $this->guard('Database', 'Job otomatis', fn () => $this->cron());
            $this->guard('Data', 'Data', fn () => $this->data());
        }
        $this->guard('Supabase', 'Supabase', fn () => $this->supabase());
        $this->guard('Operasional', 'Operasional', fn () => $this->operations());

        return $this->checks;
    }

    /** @param  list<array{status: string}>  $checks */
    public static function worst(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK);
    }

    private function configuration(): void
    {
        $g = 'Konfigurasi';
        $production = app()->environment('production');
        $this->add($g, 'Lingkungan', self::OK, 'APP_ENV='.app()->environment().($production ? '' : ' (pemeriksaan khusus production dilewati)'));
        $this->add($g, 'APP_KEY', config('app.key') ? self::OK : self::FAIL, config('app.key') ? 'terisi' : 'kosong', 'Buat dengan php artisan key:generate --show lalu isi di .env server');
        if ($production) {
            $debug = (bool) config('app.debug');
            $this->add($g, 'APP_DEBUG', $debug ? self::FAIL : self::OK, $debug ? 'true: detail error terlihat oleh pengunjung' : 'false', 'Isi APP_DEBUG=false');
            $https = str_starts_with((string) config('app.url'), 'https://');
            $this->add($g, 'APP_URL', $https ? self::OK : self::FAIL, $https ? (string) config('app.url') : 'bukan https://', 'Isi APP_URL=https://<domain>; dipakai untuk link undangan dan reset password');
            $secure = (bool) config('session.secure');
            $this->add($g, 'Cookie Secure', $secure ? self::OK : self::FAIL, $secure ? 'aktif' : 'nonaktif', 'Isi SESSION_SECURE_COOKIE=true');
            $cached = app()->configurationIsCached();
            $this->add($g, 'Cache konfigurasi', $cached ? self::OK : self::WARN, $cached ? 'aktif' : 'belum', 'Jalankan php artisan optimize setelah mengubah .env');
        }
        $driver = (string) config('database.default');
        $this->add($g, 'DB_CONNECTION', $driver === 'pgsql' ? self::OK : self::FAIL, $driver, 'Isi DB_CONNECTION=pgsql');
        foreach (['session.driver' => 'SESSION_DRIVER', 'cache.default' => 'CACHE_STORE', 'cache.stores.auth_tokens.driver' => 'AUTH_TOKEN_CACHE_DRIVER'] as $key => $env) {
            $value = (string) config($key);
            $this->add($g, $env, $value === 'database' ? self::FAIL : self::OK, $value, "Isi {$env}=file (tabel untuk driver database tidak ada)");
        }
        $built = is_file(public_path('build/manifest.json'));
        $this->add($g, 'Aset tampilan', $built ? self::OK : self::FAIL, $built ? 'public/build tersedia' : 'public/build/manifest.json tidak ada', 'Jalankan npm ci && npm run build');
    }

    private function server(): void
    {
        $g = 'Server';
        $this->add($g, 'PHP', version_compare(PHP_VERSION, '8.3.0', '>=') ? self::OK : self::FAIL, PHP_VERSION, 'Butuh PHP 8.3 atau lebih baru');
        $missing = array_values(array_filter(
            ['pdo_pgsql', 'intl', 'sodium', 'curl', 'mbstring', 'openssl', 'fileinfo', 'zip', 'tokenizer', 'xml', 'ctype'],
            fn (string $extension) => ! extension_loaded($extension),
        ));
        $this->add($g, 'Ekstensi PHP', $missing === [] ? self::OK : self::FAIL, $missing === [] ? 'lengkap' : 'kurang: '.implode(', ', $missing), 'Pasang ekstensi yang kurang (mis. apt install php8.x-pgsql), lalu restart PHP-FPM');
        $unwritable = [];
        foreach (['storage/framework/sessions', 'storage/framework/cache', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
            if (! is_dir(base_path($dir)) || ! is_writable(base_path($dir))) {
                $unwritable[] = $dir;
            }
        }
        $this->add($g, 'Folder tulis', $unwritable === [] ? self::OK : self::FAIL, $unwritable === [] ? 'storage dan bootstrap/cache bisa ditulis' : 'tidak bisa ditulis: '.implode(', ', $unwritable), 'Beri izin tulis ke user PHP-FPM; di VPS: sudo chown -R sudaya:sudaya /var/www/sudaya/shared/storage');
        $free = @disk_free_space(storage_path());
        $this->add($g, 'Ruang disk', $free === false ? self::WARN : ($free < 1024 ** 3 ? self::WARN : self::OK), $free === false ? 'tidak terbaca' : self::bytes((int) $free).' kosong', 'Hapus backup/log lama atau tambah disk');
        $revision = @file_get_contents(base_path('REVISION'));
        $this->add($g, 'Versi rilis', self::OK, $revision !== false && trim($revision) !== '' ? trim($revision) : 'tidak tercatat (bukan rilis dari deploy.sh)');
    }

    private function connection(): bool
    {
        $g = 'Database';
        try {
            $times = [];
            for ($i = 0; $i < 3; $i++) {
                $start = hrtime(true);
                DB::select('select 1');
                $times[] = (hrtime(true) - $start) / 1e6;
            }
        } catch (Throwable $error) {
            $this->add($g, 'Koneksi', self::FAIL, 'gagal terhubung ('.class_basename($error).')', 'Periksa DB_URL (session pooler, sslmode=require) dan status project di Supabase Dashboard');

            return false;
        }
        sort($times);
        $ms = (int) round($times[1]);
        $this->add($g, 'Koneksi', $ms > 150 ? self::WARN : self::OK, "terhubung, {$ms} ms per query", 'Database jauh dari server: pakai project Supabase di region yang sama dengan VPS (Singapura)');

        return true;
    }

    private function schema(): void
    {
        $g = 'Database';
        $state = BaselineSchema::state();
        $this->add($g, 'Schema dasar', $state === BaselineSchema::INSTALLED ? self::OK : self::FAIL, [
            BaselineSchema::INSTALLED => 'terpasang lengkap',
            BaselineSchema::EMPTY => 'belum dipasang',
            BaselineSchema::PARTIAL => 'setengah jadi',
        ][$state], 'Untuk project kosong: php artisan migrate --force');

        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $pending = array_values(array_diff($files, $ran));
        $this->add($g, 'Migration', $pending === [] ? self::OK : self::WARN, $pending === [] ? 'semua sudah dijalankan ('.count($files).')' : count($pending).' belum dijalankan: '.implode(', ', $pending), 'Baca isi migration, lalu php artisan migrate --force');

        $noRls = DB::select("select tablename from pg_tables where schemaname = 'public' and not rowsecurity order by 1");
        $this->add($g, 'RLS', $noRls === [] ? self::OK : self::FAIL, $noRls === [] ? 'aktif di semua tabel' : 'tanpa RLS: '.implode(', ', array_column(array_map(fn ($r) => (array) $r, $noRls), 'tablename')), 'Tabel tanpa RLS bisa dibaca lewat REST Supabase: alter table public.<nama> enable row level security');
        $policies = (int) DB::scalar("select count(*) from pg_policies where schemaname = 'public'");
        $this->add($g, 'Policy RLS', $policies === 0 ? self::OK : self::WARN, $policies === 0 ? 'tidak ada (data hanya lewat server)' : "{$policies} policy di schema public", 'Aplikasi tidak memakai policy; periksa siapa yang menambahkannya');
        $realtime = (bool) DB::scalar("select to_regprocedure('realtime.send(jsonb,text,text,boolean)') is not null");
        $this->add($g, 'Realtime', $realtime ? self::OK : self::FAIL, $realtime ? 'realtime.send tersedia' : 'realtime.send tidak ada', 'Pesanan gagal disimpan tanpa fungsi ini; periksa Realtime di Supabase Dashboard');
        $mb = (int) DB::scalar('select pg_database_size(current_database())') / 1048576;
        $this->add($g, 'Ukuran database', $mb > 400 ? self::WARN : self::OK, sprintf('%d MB', $mb), 'Paket Free Supabase dibatasi 500 MB: upgrade paket sebelum penuh');
    }

    private function cron(): void
    {
        $g = 'Database';
        if (! DB::scalar("select to_regclass('cron.job') is not null")) {
            $this->add($g, 'Job pg_cron', self::FAIL, 'extension pg_cron belum aktif', 'Aktifkan di Supabase Dashboard → Database → Extensions, lalu php artisan migrate --force');

            return;
        }
        $rows = DB::select("select j.jobname, j.active,
                count(d.runid) filter (where d.status = 'succeeded') as ok,
                count(d.runid) filter (where d.status = 'failed') as failed
            from cron.job j
            left join cron.job_run_details d on d.jobid = j.jobid and d.start_time > now() - interval '15 minutes'
            where j.jobname in ('sudaya-expire-pending-orders', 'sudaya-prune-rate-limits')
            group by j.jobname, j.active order by j.jobname");
        $jobs = [];
        foreach ($rows as $row) {
            $jobs[$row->jobname] = $row;
        }
        foreach (['sudaya-expire-pending-orders' => 'Kedaluwarsa pesanan (tiap menit)', 'sudaya-prune-rate-limits' => 'Bersihkan batas percobaan (tiap 10 menit)'] as $name => $label) {
            $job = $jobs[$name] ?? null;
            if ($job === null || ! $job->active) {
                $this->add($g, $label, self::FAIL, $job === null ? 'job tidak ada' : 'job nonaktif', 'Pesanan belum dibayar tidak akan kedaluwarsa; jalankan ulang schema dasar atau aktifkan job di cron.job');
            } elseif ((int) $job->failed > 0) {
                $this->add($g, $label, self::FAIL, "{$job->failed} kali gagal dalam 15 menit", 'Lihat cron.job_run_details di SQL editor Supabase');
            } elseif ((int) $job->ok === 0) {
                $this->add($g, $label, $name === 'sudaya-expire-pending-orders' ? self::WARN : self::OK, 'belum ada jalan dalam 15 menit terakhir', 'Tunggu beberapa menit lalu cek lagi');
            } else {
                $this->add($g, $label, self::OK, "{$job->ok} kali sukses dalam 15 menit");
            }
        }
    }

    private function data(): void
    {
        $g = 'Data';
        $code = InitialData::TARGET['company']['code'];
        $companyId = DB::table('companies')->where('code', $code)->value('id');
        if ($companyId === null) {
            $this->add($g, 'Data awal', self::FAIL, "company \"{$code}\" belum ada", 'Jalankan php artisan db:seed --force');

            return;
        }
        $permissions = DB::table('permissions')->count();
        $expected = count(Rbac::PERMISSIONS);
        $this->add($g, 'Data awal', $permissions >= $expected ? self::OK : self::WARN, "company, {$permissions}/{$expected} izin, ".DB::table('roles')->where('company_id', $companyId)->count().' peran', 'Jalankan php artisan db:seed --force (aman diulang)');
        $admins = DB::table('users')
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('users.company_id', $companyId)->where('users.is_active', true)->where('roles.code', Rbac::SUPER_ADMIN_ROLE)
            ->distinct()->count('users.id');
        $this->add($g, 'Super Admin', $admins > 0 ? self::OK : self::FAIL, "{$admins} akun aktif", 'Buat akun di Supabase Dashboard → Authentication, lalu php artisan sudaya:admin-bootstrap');
        $outlets = DB::table('outlets')->where('company_id', $companyId)->where('is_active', true)->count();
        $this->add($g, 'Outlet aktif', $outlets > 0 ? self::OK : self::FAIL, "{$outlets} outlet", 'Aktifkan outlet di Admin → Outlet');
        $menu = DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)->count();
        $this->add($g, 'Menu aktif', $menu > 0 ? self::OK : self::WARN, "{$menu} menu", 'Pelanggan melihat menu kosong: isi harga dan aktifkan menu di Admin → Menu');
        $unpriced = DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)->where('base_price', '<=', 0)->count();
        $this->add($g, 'Harga menu', $unpriced === 0 ? self::OK : self::WARN, $unpriced === 0 ? 'semua menu aktif sudah berharga' : "{$unpriced} menu aktif masih \"Harga menyusul\" (belum bisa dipesan)", 'Isi harga di Admin → Menu (ikon pensil)');
        $demo = DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('sku', 'like', 'CONTOH-%')->count();
        $this->add($g, 'Menu contoh', $demo === 0 ? self::OK : self::WARN, $demo === 0 ? 'tidak ada' : "{$demo} menu contoh masih ada", 'Hapus sebelum buka: php artisan sudaya:demo-menu --remove');
    }

    private function supabase(): void
    {
        $g = 'Supabase';
        $url = (string) config('services.supabase.url');
        $configured = $url !== '' && (string) config('services.supabase.publishable_key') !== '' && (string) config('services.supabase.service_role_key') !== '';
        $this->add($g, 'Kunci API', $configured ? self::OK : self::FAIL, $configured ? 'SUPABASE_URL dan kunci terisi' : 'ada yang kosong', 'Isi SUPABASE_URL, SUPABASE_PUBLISHABLE_KEY, SUPABASE_SERVICE_ROLE_KEY dari Supabase Dashboard → Settings → API');
        if (! $configured) {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $dbUser = (string) parse_url((string) config('database.connections.pgsql.url'), PHP_URL_USER);
        if (str_ends_with($host, '.supabase.co') && str_starts_with($dbUser, 'postgres.')) {
            $same = strtolower(explode('.', $host)[0]) === strtolower(substr($dbUser, strlen('postgres.')));
            $this->add($g, 'Project sama', $same ? self::OK : self::FAIL, $same ? 'DB_URL dan SUPABASE_URL dari project yang sama' : 'DB_URL dan SUPABASE_URL dari project BERBEDA', 'Salin ulang keduanya dari project yang sama (Dashboard → Connect dan Settings → API)');
        }

        try {
            $response = Http::baseUrl($url)->acceptJson()->connectTimeout(5)->timeout(10)->get('/auth/v1/.well-known/jwks.json');
            $keys = (array) $response->json('keys');
            $asymmetric = array_filter($keys, fn ($key) => is_array($key) && in_array($key['kty'] ?? null, ['EC', 'RSA'], true));
            if ($response->failed()) {
                $this->add($g, 'Login (JWKS)', self::FAIL, 'JWKS tidak bisa diambil ('.$response->status().')', 'Periksa SUPABASE_URL');
            } else {
                $this->add($g, 'Login (JWKS)', $asymmetric !== [] ? self::OK : self::FAIL, $asymmetric !== [] ? count($asymmetric).' kunci tanda tangan asimetris' : 'tidak ada kunci asimetris', 'Aktifkan JWT Signing Keys (ES256) di Supabase Dashboard → Settings → JWT Keys; tanpa itu login gagal');
            }
        } catch (Throwable $error) {
            $this->add($g, 'Login (JWKS)', self::FAIL, 'Supabase tidak terjangkau ('.class_basename($error).')', 'Periksa koneksi internet server dan SUPABASE_URL');
        }

        try {
            $results = $this->buckets->sync(apply: false);
            $bad = array_values(array_filter($results, fn (array $row) => $row['status'] !== 'ok'));
            $public = $this->buckets->unexpectedPublic();
            $message = $bad === [] ? 'menu-images publik, bukti stok & foto absen privat' : implode(', ', array_map(fn (array $row) => "{$row['bucket']} {$row['status']}", $bad));
            if ($public !== []) {
                $message .= '; bucket publik lain: '.implode(', ', $public);
            }
            $this->add($g, 'Storage', $bad === [] && $public === [] ? self::OK : self::FAIL, $message, 'Jalankan php artisan sudaya:storage-setup');
        } catch (Throwable $error) {
            $this->add($g, 'Storage', self::FAIL, 'bucket tidak bisa dibaca ('.class_basename($error).')', 'Periksa SUPABASE_URL dan SUPABASE_SERVICE_ROLE_KEY');
        }
    }

    private function operations(): void
    {
        $g = 'Operasional';
        $beat = @filemtime(storage_path(self::HEARTBEAT));
        $fresh = $beat !== false && time() - $beat < 15 * 60;
        $this->add($g, 'Cron Laravel', $fresh ? self::OK : self::WARN, $fresh ? 'schedule:run berjalan' : ($beat === false ? 'belum pernah berjalan' : 'terakhir '.self::ago($beat)), 'Pasang deploy/cron.sudaya ke /etc/cron.d/sudaya (schedule:run tiap menit); tanpa itu backup harian dan hapus foto absen > 90 hari tidak berjalan');

        $dir = (string) config('sudaya.backup_dir');
        if ($dir === '') {
            $this->add($g, 'Backup database', self::WARN, 'belum diatur (BACKUP_DIR kosong)', 'Isi BACKUP_DIR (VPS: /var/backups/sudaya); backup berjalan otomatis 02.30 WIB lewat scheduler');
        } else {
            $latest = 0;
            foreach (glob(rtrim($dir, '/\\').'/*.dump') ?: [] as $file) {
                $latest = max($latest, (int) @filemtime($file));
            }
            $fresh = $latest > 0 && time() - $latest < 26 * 3600;
            $this->add($g, 'Backup database', $fresh ? self::OK : self::WARN, $latest === 0 ? 'belum ada file backup' : 'terakhir '.self::ago($latest), 'Lihat storage/logs/backup.log, atau jalankan php artisan sudaya:backup');
        }

        $errors = $this->recentLogErrors();
        $this->add($g, 'Error 24 jam', $errors === 0 ? self::OK : self::WARN, "{$errors} error tercatat", 'Lihat storage/logs/laravel-*.log');
    }

    /** Jumlah baris ERROR/CRITICAL/ALERT/EMERGENCY 24 jam terakhir di log Laravel (dibaca 2 MB terakhir per file). */
    private function recentLogErrors(): int
    {
        $since = time() - 86400;
        $count = 0;
        foreach (glob(storage_path('logs/laravel*.log')) ?: [] as $file) {
            if ((int) @filemtime($file) < $since) {
                continue;
            }
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            $size = (int) filesize($file);
            fseek($handle, max(0, $size - 2 * 1024 * 1024));
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:]{8})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $match) && strtotime($match[1]) >= $since) {
                    $count++;
                }
            }
            fclose($handle);
        }

        return $count;
    }

    private function guard(string $group, string $label, callable $check): void
    {
        try {
            $check();
        } catch (Throwable $error) {
            $this->add($group, $label, self::FAIL, 'pemeriksaan gagal ('.class_basename($error).')', 'Jalankan ulang; bila tetap gagal, lihat storage/logs');
        }
    }

    private function add(string $group, string $label, string $status, string $message, ?string $hint = null): void
    {
        $this->checks[] = ['group' => $group, 'label' => $label, 'status' => $status, 'message' => $message, 'hint' => $status === self::OK ? null : $hint];
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? sprintf('%.1f GB', $bytes / 1024 ** 3) : sprintf('%d MB', $bytes / 1024 ** 2);
    }

    private static function ago(int $timestamp): string
    {
        $minutes = intdiv(max(0, time() - $timestamp), 60);

        return $minutes < 60 ? "{$minutes} menit lalu" : ($minutes < 2880 ? intdiv($minutes, 60).' jam lalu' : intdiv($minutes, 1440).' hari lalu');
    }
}
