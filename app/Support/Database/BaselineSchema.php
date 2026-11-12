<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Schema dasar Sudaya untuk database Supabase kosong.
 *
 * Isinya 10 migration Drizzle dari repo Next.js (`drizzle/0000_init.sql` …
 * `0009_cash_movements.sql`), disalin apa adanya ke `database/schema/baseline`.
 * Sejak 12 November 2026 repo Laravel bisa memasang database baru sendiri lewat
 * `php artisan migrate`, tanpa repo Next.js.
 *
 * Database yang schema-nya sudah lengkap, misalnya development, tidak diubah sama sekali.
 * Schema setengah jadi (sebagian tabel ada) ditolak, tidak ditimpa.
 */
final class BaselineSchema
{
    public const EMPTY = 'empty';

    public const INSTALLED = 'installed';

    public const PARTIAL = 'partial';

    /** Urutan sama dengan `drizzle/meta/_journal.json`. */
    public const FILES = [
        '0000_init.sql',
        '0001_catalog_slug_sku_soft_delete.sql',
        '0002_order_access_token.sql',
        '0003_hardening_inventory.sql',
        '0004_ops_functions.sql',
        '0005_inventory_document_ref.sql',
        '0006_attendance.sql',
        '0007_audit_logs_immutable.sql',
        '0008_order_request_hash.sql',
        '0009_cash_movements.sql',
    ];

    /** Pemisah statement drizzle-kit. */
    private const BREAKPOINT = '--> statement-breakpoint';

    public static function directory(): string
    {
        return database_path('schema/baseline');
    }

    /**
     * Semua statement, urut per file.
     *
     * @return list<string>
     */
    public static function statements(): array
    {
        $statements = [];
        foreach (self::FILES as $file) {
            foreach (self::split(self::read($file)) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * Pecah satu file seperti migrator drizzle-kit. Potongan yang hanya berisi spasi atau
     * komentar dibuang, karena PostgreSQL menolak query kosong.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        foreach (explode(self::BREAKPOINT, str_replace("\r\n", "\n", $sql)) as $part) {
            $part = trim($part);
            if (trim((string) preg_replace('/^\s*--.*$/m', '', $part)) !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }

    /**
     * Nama tabel `public` yang dibuat schema dasar.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return self::names('/CREATE TABLE (?:IF NOT EXISTS )?"(?:public"\.")?([a-z0-9_]+)"/');
    }

    /**
     * Nama tipe enum `public` yang dibuat schema dasar.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return self::names('/CREATE TYPE "public"\."([a-z0-9_]+)"/');
    }

    /** Kondisi database tujuan: kosong, sudah terpasang, atau setengah jadi. */
    public static function state(): string
    {
        $tables = (int) DB::scalar(
            'select count(*) from pg_tables where schemaname = ? and tablename = any(?::text[])',
            ['public', self::pgArray(self::tables())],
        );
        $types = (int) DB::scalar(
            'select count(*) from pg_type t join pg_namespace n on n.oid = t.typnamespace where n.nspname = ? and t.typname = any(?::text[])',
            ['public', self::pgArray(self::types())],
        );

        if ($tables === 0 && $types === 0) {
            return self::EMPTY;
        }

        return $tables === count(self::tables()) && $types === count(self::types()) ? self::INSTALLED : self::PARTIAL;
    }

    /**
     * Prasyarat project Supabase yang belum terpenuhi (kosong = siap dipasang).
     *
     * @return list<string>
     */
    public static function missingPrerequisites(): array
    {
        $missing = [];
        if ((int) DB::scalar("select current_setting('server_version_num')::int") < 150000) {
            $missing[] = 'PostgreSQL minimal versi 15 (dibutuhkan UNIQUE NULLS NOT DISTINCT).';
        }
        if (! DB::scalar("select to_regclass('auth.users') is not null")) {
            $missing[] = 'Schema auth tidak ditemukan: database ini bukan project Supabase.';
        }
        if ((int) DB::scalar("select count(*) from pg_roles where rolname in ('anon', 'authenticated')") !== 2) {
            $missing[] = 'Role anon/authenticated Supabase tidak ditemukan.';
        }
        if (! DB::scalar("select to_regprocedure('realtime.send(jsonb,text,text,boolean)') is not null")) {
            $missing[] = 'Fungsi realtime.send tidak ditemukan: Realtime Supabase belum aktif di project ini.';
        }
        if (! DB::scalar("select exists (select 1 from pg_available_extensions where name = 'pg_cron')")) {
            $missing[] = 'Extension pg_cron tidak tersedia. Aktifkan di Supabase Dashboard → Database → Extensions.';
        }

        return $missing;
    }

    /**
     * Laravel membuat tabel `migrations` di schema public sebelum migration pertama berjalan.
     * Tanpa RLS, tabel itu bisa dibaca dan diubah lewat REST Supabase dengan kunci publik,
     * jadi dikunci lebih dulu, juga bila pemasangan schema nanti ditolak.
     */
    public static function protectMigrationsTable(): void
    {
        $table = 'public.'.config('database.migrations.table', 'migrations');
        $rls = DB::scalar('select relrowsecurity from pg_class where oid = to_regclass(?)', [$table]);
        if (in_array($rls, [false, 'f', 0, '0'], true)) {
            DB::statement("alter table {$table} enable row level security");
        }
    }

    /** Pasang seluruh schema dasar. Dipanggil dari migration, di dalam satu transaksi. */
    public static function install(): void
    {
        foreach (self::statements() as $statement) {
            DB::unprepared($statement);
        }
    }

    private static function read(string $file): string
    {
        $sql = @file_get_contents(self::directory().'/'.$file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("File schema {$file} tidak ditemukan atau kosong.");
        }

        return $sql;
    }

    /** @return list<string> */
    private static function names(string $pattern): array
    {
        $names = [];
        foreach (self::FILES as $file) {
            preg_match_all($pattern, self::read($file), $matches);
            array_push($names, ...$matches[1]);
        }

        return array_values(array_unique($names));
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }
}
