<?php

namespace App\Services\System;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Backup database Supabase dengan pg_dump (format custom, terkompresi): schema `public`
 * (data bisnis) dan `auth` (akun login staf). File dicek dengan `pg_restore --list` sebelum
 * disimpan, diberi izin 0600, dan backup lebih tua dari batas hari dihapus.
 *
 * Data yang tidak dibutuhkan saat restore tidak ikut: catatan migration (dibuat ulang oleh
 * `migrate`) serta token sesi/login sementara Supabase Auth (rahasia, dan tidak berlaku di
 * project baru: staf cukup login ulang).
 *
 * pg_dump wajib versi mayor ≥ server (Supabase: 17). Kredensial dioper lewat variabel
 * lingkungan PG*, tidak pernah lewat argumen perintah.
 */
final class DatabaseBackup
{
    public const PREFIX = 'sudaya-';

    public const EXTENSION = '.dump';

    /** Tabel yang strukturnya ikut di-backup, tetapi datanya tidak. */
    public const EXCLUDED_DATA = [
        'public.migrations',
        'auth.schema_migrations',
        'auth.sessions',
        'auth.refresh_tokens',
        'auth.mfa_amr_claims',
        'auth.mfa_challenges',
        'auth.flow_state',
        'auth.one_time_tokens',
        'auth.audit_log_entries',
    ];

    /**
     * Variabel lingkungan libpq dari konfigurasi koneksi Laravel (DB_URL atau host/port/...).
     *
     * @param  array<string, mixed>  $connection
     * @return array{PGHOST: string, PGPORT: string, PGUSER: string, PGPASSWORD: string, PGDATABASE: string, PGSSLMODE: string}
     */
    public static function environment(array $connection): array
    {
        $url = (string) ($connection['url'] ?? '');
        if ($url !== '') {
            $parts = parse_url($url);
            if ($parts === false || ! isset($parts['host'])) {
                throw new RuntimeException('DB_URL tidak bisa dibaca.');
            }
            parse_str($parts['query'] ?? '', $query);

            return [
                'PGHOST' => $parts['host'],
                'PGPORT' => (string) ($parts['port'] ?? 5432),
                'PGUSER' => rawurldecode($parts['user'] ?? ''),
                'PGPASSWORD' => rawurldecode($parts['pass'] ?? ''),
                'PGDATABASE' => ltrim(rawurldecode($parts['path'] ?? '/postgres'), '/') ?: 'postgres',
                'PGSSLMODE' => (string) ($query['sslmode'] ?? $connection['sslmode'] ?? 'require'),
            ];
        }

        return [
            'PGHOST' => (string) ($connection['host'] ?? ''),
            'PGPORT' => (string) ($connection['port'] ?? 5432),
            'PGUSER' => (string) ($connection['username'] ?? ''),
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGDATABASE' => (string) ($connection['database'] ?? 'postgres'),
            'PGSSLMODE' => (string) ($connection['sslmode'] ?? 'require'),
        ];
    }

    /**
     * @param  array<string, string>  $env  hasil environment()
     * @param  string|list<string>  $pgDump  program (atau awalan perintah) pg_dump
     * @param  string|list<string>  $pgRestore  program (atau awalan perintah) pg_restore
     * @return array{file: string, bytes: int, tables: int, removed: list<string>}
     */
    public function run(string $dir, int $keepDays, array $env, string|array $pgDump = 'pg_dump', string|array $pgRestore = 'pg_restore'): array
    {
        $dir = rtrim($dir, '/\\');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Folder backup {$dir} tidak bisa dibuat.");
        }
        $name = self::PREFIX.gmdate('Ymd-His').self::EXTENSION;
        $final = $dir.DIRECTORY_SEPARATOR.$name;
        $part = $final.'.part';

        $handle = @fopen($part, 'wb');
        if ($handle === false) {
            throw new RuntimeException("File {$part} tidak bisa ditulis.");
        }
        @chmod($part, 0600);
        $error = '';
        $dump = new Process(
            [
                ...(array) $pgDump, '--format=custom', '--no-owner', '--no-privileges', '--schema=public', '--schema=auth',
                ...array_map(fn (string $table) => '--exclude-table-data='.$table, self::EXCLUDED_DATA),
            ],
            null,
            $env + ['PGCONNECT_TIMEOUT' => '15'],
            null,
            1800,
        );
        try {
            $dump->run(function (string $type, string $buffer) use ($handle, &$error) {
                $type === Process::OUT ? fwrite($handle, $buffer) : $error .= $buffer;
            });
        } finally {
            fclose($handle);
        }
        if (! $dump->isSuccessful() || (int) filesize($part) === 0) {
            @unlink($part);
            throw new RuntimeException('pg_dump gagal (exit '.$dump->getExitCode().'): '.self::clean($error, $env));
        }

        $input = fopen($part, 'rb');
        $list = new Process([...(array) $pgRestore, '--list'], null, $env, $input, 600);
        try {
            $list->run();
        } finally {
            is_resource($input) && fclose($input);
        }
        $tables = preg_match_all('/ TABLE DATA (public|auth) /', $list->getOutput());
        if (! $list->isSuccessful() || $tables === 0) {
            @unlink($part);
            throw new RuntimeException('File backup rusak: pg_restore --list gagal ('.self::clean($list->getErrorOutput(), $env).').');
        }
        if (! @rename($part, $final)) {
            @unlink($part);
            throw new RuntimeException("File backup tidak bisa disimpan sebagai {$final}.");
        }
        @chmod($final, 0600);

        return ['file' => $final, 'bytes' => (int) filesize($final), 'tables' => $tables, 'removed' => $this->prune($dir, $keepDays, $final)];
    }

    /**
     * Hapus backup lebih tua dari batas hari. File terbaru tidak pernah dihapus.
     *
     * @return list<string>
     */
    public function prune(string $dir, int $keepDays, string $keep): array
    {
        $removed = [];
        $limit = time() - max(1, $keepDays) * 86400;
        foreach (glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.self::PREFIX.'*'.self::EXTENSION) ?: [] as $file) {
            if (realpath($file) !== realpath($keep) && (int) @filemtime($file) < $limit && @unlink($file)) {
                $removed[] = basename($file);
            }
        }

        return $removed;
    }

    /** Pesan error pg_dump/psql tanpa password atau URL koneksi. */
    public static function clean(string $message, array $env): string
    {
        $message = trim($message);
        if (($env['PGPASSWORD'] ?? '') !== '') {
            $message = str_replace($env['PGPASSWORD'], '***', $message);
        }

        return mb_substr((string) preg_replace('#postgres(ql)?://\S+#i', 'postgresql://***', $message), 0, 500);
    }
}
